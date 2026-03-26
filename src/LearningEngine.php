<?php

namespace GlpiPlugin\Agentassistant;

/**
 * LearningEngine — tracks technician feedback to improve future suggestions.
 *
 * Weight rules:
 *  - 'used'      → weight_delta = +0.10  (suggestion was helpful)
 *  - 'dismissed' → weight_delta = -0.05  (suggestion was not helpful)
 *  - 'ignored'   → weight_delta = 0      (no signal)
 *
 * The source_hash identifies the corpus of similar tickets used.
 * SimilarityEngine multiplies the raw cosine score by the accumulated weight.
 */
class LearningEngine
{
    private const DELTA_USED      =  0.10;
    private const DELTA_DISMISSED = -0.05;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Record a technician action on a suggestion.
     *
     * @param int    $suggestionId
     * @param int    $ticketId
     * @param string $action  'used' | 'dismissed' | 'ignored'
     * @param int    $userId  Current technician
     */
    public function recordFeedback(
        int    $suggestionId,
        int    $ticketId,
        string $action,
        int    $userId = 0
    ): void {
        global $DB;

        $action = in_array($action, ['used', 'dismissed', 'ignored'], true)
            ? $action
            : 'ignored';

        $delta = match ($action) {
            'used'      => self::DELTA_USED,
            'dismissed' => self::DELTA_DISMISSED,
            default     => 0.0,
        };

        // Retrieve source_hash from the suggestion
        $row = $DB->request([
            'SELECT' => ['source_ids'],
            'FROM'   => 'glpi_plugin_agentassistant_suggestions',
            'WHERE'  => ['id' => $suggestionId],
            'LIMIT'  => 1,
        ]);

        $sourceHash = '';
        if ($row->count() > 0) {
            $sourceHash = md5($row->current()['source_ids'] ?? '');
        }

        $DB->insert('glpi_plugin_agentassistant_learning', [
            'suggestion_id' => $suggestionId,
            'tickets_id'    => $ticketId,
            'users_id'      => $userId,
            'action'        => $action,
            'source_hash'   => $sourceHash,
            'weight_delta'  => $delta,
            'date_action'   => date('Y-m-d H:i:s'),
        ]);

        // Update suggestion status
        $status = match ($action) {
            'used'      => 'used',
            'dismissed' => 'dismissed',
            default     => 'shown',
        };
        $DB->update(
            'glpi_plugin_agentassistant_suggestions',
            ['status' => $status],
            ['id' => $suggestionId]
        );
    }

    /**
     * Return learning statistics for the admin dashboard.
     *
     * @return array{total:int, used:int, dismissed:int, use_rate:float}
     */
    public function getStats(): array
    {
        global $DB;

        $res = $DB->doQuery(
            "SELECT action, COUNT(*) AS cnt
             FROM `glpi_plugin_agentassistant_learning`
             WHERE action IN ('used', 'dismissed')
             GROUP BY action"
        );

        $counts = ['used' => 0, 'dismissed' => 0];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $counts[$row['action']] = (int) $row['cnt'];
            }
        }

        $total   = $counts['used'] + $counts['dismissed'];
        $useRate = $total > 0 ? round($counts['used'] / $total * 100, 1) : 0.0;

        return [
            'total'      => $total,
            'used'       => $counts['used'],
            'dismissed'  => $counts['dismissed'],
            'use_rate'   => $useRate,
        ];
    }

    /**
     * Return top technicians by learning contribution.
     *
     * @return array[]
     */
    public function getTopContributors(int $limit = 10): array
    {
        global $DB;

        $res = $DB->doQuery(
            "SELECT l.users_id,
                    CONCAT(u.firstname, ' ', u.realname) AS fullname,
                    COUNT(*) AS interactions,
                    SUM(CASE WHEN l.action = 'used' THEN 1 ELSE 0 END) AS used_count
             FROM `glpi_plugin_agentassistant_learning` l
             LEFT JOIN `glpi_users` u ON u.id = l.users_id
             WHERE l.users_id > 0
             GROUP BY l.users_id
             ORDER BY used_count DESC
             LIMIT $limit"
        );

        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
