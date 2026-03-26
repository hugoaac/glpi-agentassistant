<?php

namespace GlpiPlugin\Agentassistant;

/**
 * SuggestionEngine — main orchestrator.
 *
 * Flow:
 *  1. Embed query ticket
 *  2. Search for similar resolved tickets
 *  3. Compute confidence score
 *  4. If confidence >= medium → build suggestion from similar tickets
 *     Else → call AI provider (fallback)
 *  5. Store suggestion
 *  6. If confidence >= high → add private followup to ticket
 */
class SuggestionEngine
{
    public function __construct(
        private SimilarityEngine $similarity = new SimilarityEngine(),
        private AIProvider       $ai         = new AIProvider(),
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Analyze a ticket and produce (or update) a suggestion.
     * Called from the cron queue processor.
     */
    public function analyze(int $ticketId): ?array
    {
        if (!Config::getBool('enabled')) {
            return null;
        }

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return null;
        }

        $start   = microtime(true);
        $similar = $this->similarity->findSimilar($ticketId);
        $conf    = SimilarityEngine::computeConfidence($similar);

        $confHigh   = Config::getInt('confidence_high');
        $confMedium = Config::getInt('confidence_medium');

        $sourceType = 'similar';
        $text       = '';
        $tokens     = 0;

        if ($conf >= $confMedium && !empty($similar)) {
            // ── Build suggestion from similar tickets ──────────────────────
            $text = $this->buildSimilarSuggestion($ticket, $similar, $conf);
        } else {
            // ── AI fallback ────────────────────────────────────────────────
            $category = $this->getCategoryName($ticket);
            $result   = $this->ai->generateSuggestion([
                'id'          => $ticketId,
                'title'       => $ticket->fields['name']    ?? '',
                'description' => strip_tags($ticket->fields['content'] ?? ''),
                'category'    => $category,
            ], $similar);

            if ($result !== null) {
                $text       = $result['text'];
                $tokens     = $result['tokens'];
                $sourceType = empty($similar) ? 'ai' : 'hybrid';
                // AI baseline confidence
                $conf = empty($similar) ? 70 : min(100, $conf + 15);
            }
        }

        if (empty($text)) {
            return null;
        }

        $explanation = $this->buildExplanation($similar, $sourceType);
        $sourceIds   = json_encode(array_column($similar, 'ticket_id'));

        $ms = (int) ((microtime(true) - $start) * 1000);

        // ── Store suggestion ───────────────────────────────────────────────
        $suggestionId = $this->storeSuggestion(
            $ticketId, $conf, $sourceType, $sourceIds, $text, $explanation
        );

        // ── Auto followup if confidence is high ────────────────────────────
        $followupId = null;
        if ($conf >= $confHigh && Config::getBool('auto_followup')) {
            $followupId = $this->addPrivateFollowup($ticketId, $text, $similar, $conf);
            if ($followupId) {
                global $DB;
                $DB->update(
                    'glpi_plugin_agentassistant_suggestions',
                    ['followup_id' => $followupId],
                    ['id' => $suggestionId]
                );
            }
        }

        // ── Log ───────────────────────────────────────────────────────────
        global $DB;
        $DB->insert('glpi_plugin_agentassistant_logs', [
            'tickets_id'       => $ticketId,
            'operation'        => 'analyze',
            'confidence_score' => $conf,
            'source_type'      => $sourceType,
            'tokens_used'      => $tokens,
            'duration_ms'      => $ms,
            'message'          => sprintf('similar=%d conf=%d', count($similar), $conf),
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);

        return [
            'suggestion_id'    => $suggestionId,
            'confidence_score' => $conf,
            'source_type'      => $sourceType,
            'text'             => $text,
            'explanation'      => $explanation,
            'followup_id'      => $followupId,
        ];
    }

    /**
     * Retrieve the latest suggestion for a ticket (for the JS panel).
     */
    public function getForTicket(int $ticketId): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT'  => ['id', 'confidence_score', 'source_type', 'source_ids',
                          'suggestion_text', 'explanation', 'followup_id', 'status'],
            'FROM'    => 'glpi_plugin_agentassistant_suggestions',
            'WHERE'   => ['tickets_id' => $ticketId],
            'ORDER'   => ['date_creation DESC'],
            'LIMIT'   => 1,
        ]);

        if ($row->count() === 0) {
            return null;
        }

        $data = $row->current();

        // Mark as shown
        if ($data['status'] === 'pending') {
            $DB->update(
                'glpi_plugin_agentassistant_suggestions',
                ['status' => 'shown'],
                ['id' => $data['id']]
            );
        }

        return [
            'id'               => (int) $data['id'],
            'confidence_score' => (int) $data['confidence_score'],
            'source_type'      => $data['source_type'],
            'source_ids'       => json_decode($data['source_ids'] ?? '[]', true),
            'text'             => $data['suggestion_text'],
            'explanation'      => $data['explanation'],
            'followup_id'      => $data['followup_id'],
            'status'           => $data['status'],
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function buildSimilarSuggestion(\Ticket $ticket, array $similar, int $conf): string
    {
        $lines   = [];
        $lines[] = '## Sugestao de Solucao (IA Assistente)';
        $lines[] = '';
        $lines[] = 'Com base em incidentes similares resolvidos anteriormente:';
        $lines[] = '';

        foreach ($similar as $s) {
            $pct = (int) round($s['score'] * 100);
            $lines[] = sprintf('- **Chamado #%d** — %s (%d%% similar)', $s['ticket_id'], $s['title'], $pct);
        }

        $lines[] = '';
        $lines[] = '### Solucoes aplicadas anteriormente:';
        $lines[] = '';

        foreach ($similar as $i => $s) {
            $res = trim(strip_tags($s['resolution'] ?? ''));
            if (empty($res)) {
                continue;
            }
            $lines[] = sprintf('**Chamado #%d:**', $s['ticket_id']);
            // Show first 300 chars of resolution
            $lines[] = substr($res, 0, 300) . (strlen($res) > 300 ? '...' : '');
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = sprintf('*Confianca: %d%% | Fonte: %d chamado(s) similar(es)*', $conf, count($similar));

        return implode("\n", $lines);
    }

    private function buildExplanation(array $similar, string $sourceType): string
    {
        $parts = [];

        if (!empty($similar)) {
            $refs = array_map(fn($s) => 'Chamado #' . $s['ticket_id'], $similar);
            $parts[] = 'Baseado em: ' . implode(', ', $refs);
        }

        if ($sourceType === 'ai' || $sourceType === 'hybrid') {
            $parts[] = 'Complementado pela IA (Claude)';
        }

        return implode(' | ', $parts);
    }

    private function storeSuggestion(
        int    $ticketId,
        int    $conf,
        string $sourceType,
        string $sourceIds,
        string $text,
        string $explanation
    ): int {
        global $DB;

        // Remove previous suggestions for this ticket to keep 1 active
        $DB->delete('glpi_plugin_agentassistant_suggestions', ['tickets_id' => $ticketId]);

        $DB->insert('glpi_plugin_agentassistant_suggestions', [
            'tickets_id'       => $ticketId,
            'confidence_score' => $conf,
            'source_type'      => $sourceType,
            'source_ids'       => $sourceIds,
            'suggestion_text'  => $text,
            'explanation'      => $explanation,
            'status'           => 'pending',
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $DB->insertId();
    }

    private function addPrivateFollowup(int $ticketId, string $text, array $similar, int $conf): ?int
    {
        $content = $this->formatFollowupContent($text, $similar, $conf);

        $followup = new \ITILFollowup();
        $newId    = $followup->add([
            'itemtype'        => \Ticket::class,
            'items_id'        => $ticketId,
            'content'         => $content,
            'is_private'      => 1,
            'users_id'        => 0,
            'requesttypes_id' => 0,
        ]);

        return $newId ? (int) $newId : null;
    }

    private function formatFollowupContent(string $text, array $similar, int $conf): string
    {
        $emoji = $conf >= Config::getInt('confidence_high') ? '🟢' : '🟡';

        $html  = '<div class="agent-assistant-followup">';
        $html .= '<strong>' . $emoji . ' Sugestao do Agente IA</strong><br>';

        if (!empty($similar)) {
            $refs = array_map(fn($s) => sprintf(
                '<a href="/front/ticket.form.php?id=%d">#%d</a>',
                $s['ticket_id'], $s['ticket_id']
            ), $similar);
            $html .= '<em>Baseado em: ' . implode(', ', $refs) . '</em><br>';
        }

        $html .= '<br>';
        // Convert markdown-ish text to basic HTML
        $body = nl2br(htmlspecialchars($text));
        $body = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body);
        $body = preg_replace('/##\s*(.+?)<br \/>/', '<h4>$1</h4>', $body);
        $html .= $body;
        $html .= '<br><small><em>Confianca: ' . $conf . '%</em></small>';
        $html .= '</div>';

        return $html;
    }

    private function getCategoryName(\Ticket $ticket): string
    {
        $catId = (int) ($ticket->fields['itilcategories_id'] ?? 0);
        if ($catId === 0) {
            return '';
        }
        $cat = new \ITILCategory();
        return $cat->getFromDB($catId) ? ($cat->fields['completename'] ?? '') : '';
    }
}
