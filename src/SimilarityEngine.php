<?php

namespace GlpiPlugin\Agentassistant;

/**
 * SimilarityEngine — find past tickets similar to a given ticket.
 *
 * Algorithm:
 *  1. Compute/retrieve embedding for the query ticket
 *  2. Load all stored embeddings from closed/solved tickets
 *  3. Compute cosine similarity (+ Jaccard fallback for sparse vectors)
 *  4. Boost by learning weights (used suggestions score higher)
 *  5. Return top-N results above threshold, with resolution notes
 */
class SimilarityEngine
{
    private const CLOSED_STATUSES = [
        \Ticket::CLOSED,
        \Ticket::SOLVED,
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Find tickets similar to $queryTicketId.
     *
     * @return array  Each entry: {ticket_id, score, title, solution, resolution}
     */
    public function findSimilar(int $queryTicketId): array
    {
        global $DB;

        $threshold = Config::getFloat('similarity_threshold');
        $maxItems  = Config::getInt('max_similar_tickets');

        // Ensure query ticket embedding exists
        EmbeddingService::embedTicket($queryTicketId);

        $queryRow = $DB->request([
            'SELECT' => ['embedding_json', 'keywords_json'],
            'FROM'   => 'glpi_plugin_agentassistant_embeddings',
            'WHERE'  => ['tickets_id' => $queryTicketId],
            'LIMIT'  => 1,
        ]);
        if ($queryRow->count() === 0) {
            return [];
        }
        $queryVec      = json_decode($queryRow->current()['embedding_json'], true) ?? [];
        $queryKeywords = json_decode($queryRow->current()['keywords_json'], true)  ?? [];

        // Load embeddings of resolved tickets (exclude self)
        $embeddings = $this->loadResolvedEmbeddings($queryTicketId);
        if (empty($embeddings)) {
            return [];
        }

        // Load learning weights (source_hash → float weight multiplier)
        $weights = $this->loadLearningWeights();

        // Score each candidate
        $scored = [];
        foreach ($embeddings as $row) {
            $candVec      = json_decode($row['embedding_json'], true) ?? [];
            $candKeywords = json_decode($row['keywords_json'],  true)  ?? [];

            $cos     = EmbeddingService::cosineSimilarity($queryVec, $candVec);
            $jaccard = EmbeddingService::jaccardSimilarity($queryKeywords, $candKeywords);

            // Combined score: cosine carries 80 %, Jaccard 20 % (fallback for sparse)
            $score = ($cos * 0.80) + ($jaccard * 0.20);

            // Apply learning weight (hash based on the candidate ticket embedding)
            $hash   = md5($row['embedding_json']);
            $weight = $weights[$hash] ?? 1.0;
            $score  = min(1.0, $score * $weight);

            if ($score >= $threshold) {
                $scored[] = [
                    'ticket_id' => (int) $row['tickets_id'],
                    'score'     => $score,
                    'hash'      => $hash,
                ];
            }
        }

        if (empty($scored)) {
            return [];
        }

        // Sort DESC by score
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, $maxItems);

        // Enrich with ticket data + resolution
        return $this->enrichWithTicketData($scored);
    }

    /**
     * Convert raw similarity score (0–1) to a confidence percentage (0–100),
     * considering how many similar tickets were found.
     *
     * @param array $similar  Output of findSimilar()
     */
    public static function computeConfidence(array $similar): int
    {
        if (empty($similar)) {
            return 0;
        }

        $topScore = $similar[0]['score'];    // 0–1
        $count    = count($similar);

        // Base: top score as percentage
        $confidence = (int) round($topScore * 100);

        // Boost up to +10 pts when multiple high-quality matches exist
        if ($count >= 3 && $topScore >= 0.6) {
            $confidence = min(100, $confidence + 8);
        } elseif ($count >= 2 && $topScore >= 0.5) {
            $confidence = min(100, $confidence + 4);
        }

        return $confidence;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function loadResolvedEmbeddings(int $excludeTicketId): array
    {
        global $DB;

        $ticketTable    = \Ticket::getTable();
        $embeddingTable = 'glpi_plugin_agentassistant_embeddings';

        $statusList = implode(',', self::CLOSED_STATUSES);

        $res = $DB->doQuery(
            "SELECT e.tickets_id, e.embedding_json, e.keywords_json
             FROM `$embeddingTable` e
             INNER JOIN `$ticketTable` t ON t.id = e.tickets_id
             WHERE t.status IN ($statusList)
               AND t.is_deleted = 0
               AND e.tickets_id != $excludeTicketId"
        );

        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function loadLearningWeights(): array
    {
        global $DB;

        $res = $DB->doQuery(
            "SELECT source_hash, SUM(weight_delta) AS total_delta
             FROM `glpi_plugin_agentassistant_learning`
             WHERE source_hash != ''
             GROUP BY source_hash"
        );

        $weights = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                // Weight = 1.0 + cumulative delta (capped 0.5 – 2.0)
                $weights[$row['source_hash']] = max(0.5, min(2.0, 1.0 + (float) $row['total_delta']));
            }
        }
        return $weights;
    }

    private function enrichWithTicketData(array $scored): array
    {
        global $DB;

        $ids      = array_column($scored, 'ticket_id');
        $indexById = array_column($scored, null, 'ticket_id');

        if (empty($ids)) {
            return [];
        }

        $idList = implode(',', $ids);

        $ticketTable   = \Ticket::getTable();
        $followupTable = \ITILFollowup::getTable();

        // Ticket base data
        $res = $DB->doQuery(
            "SELECT t.id, t.name,
                    GROUP_CONCAT(f.content ORDER BY f.id ASC SEPARATOR '\n---\n') AS followups
             FROM `$ticketTable` t
             LEFT JOIN `$followupTable` f ON f.items_id = t.id
                  AND f.itemtype = 'Ticket'
                  AND f.is_private = 0
             WHERE t.id IN ($idList)
             GROUP BY t.id"
        );

        $results = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $id      = (int) $row['id'];
                $scored  = $indexById[$id];
                $results[] = [
                    'ticket_id'  => $id,
                    'score'      => $scored['score'],
                    'hash'       => $scored['hash'],
                    'title'      => $row['name'],
                    'resolution' => strip_tags((string) ($row['followups'] ?? '')),
                ];
            }
        }

        // Restore sort order (scored is already sorted)
        usort($results, function ($a, $b) use ($indexById) {
            return $indexById[$b['ticket_id']]['score'] <=> $indexById[$a['ticket_id']]['score'];
        });

        return $results;
    }
}
