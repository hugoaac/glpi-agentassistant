<?php

namespace GlpiPlugin\Agentassistant;

/**
 * AIProvider — Claude API integration (Anthropic Messages API).
 *
 * Used as fallback when similarity search finds no good matches.
 * Results are stored for future reuse (avoids repeated API calls).
 */
class AIProvider
{
    private const API_URL     = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate a solution suggestion for the given ticket.
     *
     * @param array $ticket    Fields: title, description, category
     * @param array $similar   Similar tickets from SimilarityEngine (may be empty)
     * @return array{text:string, tokens:int}|null
     */
    public function generateSuggestion(array $ticket, array $similar = []): ?array
    {
        $apiKey = Config::get('ai_api_key');
        if (empty($apiKey)) {
            return null;
        }

        $model     = Config::get('ai_model') ?: 'claude-sonnet-4-6';
        $maxTokens = Config::getInt('max_tokens') ?: 800;

        $prompt = $this->buildPrompt($ticket, $similar);

        $start = microtime(true);
        $resp  = $this->callApi($apiKey, $model, $maxTokens, $prompt);
        $ms    = (int) ((microtime(true) - $start) * 1000);

        if ($resp === null) {
            return null;
        }

        $text   = trim($resp['content'][0]['text'] ?? '');
        $tokens = (int) ($resp['usage']['output_tokens'] ?? 0);

        // Log the call
        $this->log($ticket['id'] ?? 0, 'ai_call', $tokens, $ms);

        return ['text' => $text, 'tokens' => $tokens];
    }

    // ── Prompt builder ────────────────────────────────────────────────────────

    private function buildPrompt(array $ticket, array $similar): string
    {
        $title       = $ticket['title']       ?? '';
        $description = $ticket['description'] ?? '';
        $category    = $ticket['category']    ?? '';

        $prompt  = "Você é um técnico especialista em suporte de TI. ";
        $prompt .= "Analise o chamado de suporte abaixo e forneça uma solução concisa e objetiva. ";
        $prompt .= "Responda sempre em português do Brasil.\n\n";
        $prompt .= "## Chamado\n";
        $prompt .= "**Categoria:** {$category}\n";
        $prompt .= "**Título:** {$title}\n";
        $prompt .= "**Descrição:**\n{$description}\n\n";

        if (!empty($similar)) {
            $prompt .= "## Incidentes Similares Anteriores (para referência)\n";
            foreach ($similar as $i => $s) {
                $n = $i + 1;
                $res = substr(strip_tags($s['resolution'] ?? ''), 0, 400);
                $prompt .= "**#{$n} — Chamado #{$s['ticket_id']}: {$s['title']}**\n";
                if ($res) {
                    $prompt .= "Resolução: {$res}\n";
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "## Tarefa\n";
        $prompt .= "Forneça uma solução passo a passo para este chamado. ";
        $prompt .= "Seja conciso (máximo 5 passos). ";
        $prompt .= "Comece diretamente com a solução — sem introdução.\n";
        $prompt .= "Formato: use passos numerados.\n";
        $prompt .= "Se referenciar chamados anteriores, liste como: 'Com base em: Chamado #X, Chamado #Y'\n";

        return $prompt;
    }

    // ── HTTP call ─────────────────────────────────────────────────────────────

    private function callApi(string $apiKey, string $model, int $maxTokens, string $prompt): ?array
    {
        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'         => $payload,
                'timeout'         => 30,
                'ignore_errors'   => true,
            ],
        ]);

        $raw = @file_get_contents(self::API_URL, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || isset($data['error'])) {
            return null;
        }

        return $data;
    }

    // ── Logger ────────────────────────────────────────────────────────────────

    private function log(int $ticketId, string $operation, int $tokens, int $ms): void
    {
        global $DB;
        $DB->insert('glpi_plugin_agentassistant_logs', [
            'tickets_id'    => $ticketId,
            'operation'     => $operation,
            'source_type'   => 'ai',
            'tokens_used'   => $tokens,
            'duration_ms'   => $ms,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }
}
