<?php

namespace GlpiPlugin\Agentassistant;

/**
 * RecurrenceDetector — detect recurring incident patterns and auto-create Problems.
 *
 * Algorithm:
 *  1. Look at tickets created in the last Y days
 *  2. For each ticket, extract top-3 keywords + category → cluster_key (MD5)
 *  3. Group tickets by cluster_key
 *  4. If any group has >= X tickets and no Problem linked yet → create Problem
 *  5. Link all tickets in the cluster to the new Problem
 *  6. Update glpi_plugin_agentassistant_clusters
 */
class RecurrenceDetector
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Main entry point — called from cron.
     *
     * @return int  Number of Problems created
     */
    public function run(): int
    {
        if (!Config::getBool('auto_problem')) {
            return 0;
        }

        $threshold = Config::getInt('recurrence_count');
        $days      = Config::getInt('recurrence_days');

        $clusters = $this->buildClusters($days);
        $created  = 0;

        foreach ($clusters as $key => $cluster) {
            if (count($cluster['ticket_ids']) < $threshold) {
                continue;
            }

            // Check if we already have an active cluster record with a Problem
            $existing = $this->getExistingCluster($key);

            if ($existing !== null && $existing['problems_id'] !== null) {
                // Already has a problem — just update ticket list
                $this->updateCluster($existing['id'], $cluster);
                continue;
            }

            // Create Problem
            $problemId = $this->createProblem($cluster);
            if ($problemId) {
                $this->linkTicketsToProblem($problemId, $cluster['ticket_ids']);
                $this->saveCluster($key, $cluster, $problemId, $existing['id'] ?? null);
                $created++;
            }
        }

        return $created;
    }

    // ── Cluster building ──────────────────────────────────────────────────────

    /**
     * Build clusters from recent tickets using keyword + category grouping.
     *
     * @return array<string, array{keywords:string[], category:string, ticket_ids:int[]}>
     */
    private function buildClusters(int $days): array
    {
        global $DB;

        $since          = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $embeddingTable = 'glpi_plugin_agentassistant_embeddings';
        $ticketTable    = \Ticket::getTable();
        $categoryTable  = 'glpi_itilcategories';

        // Only open/new/processing tickets (not already solved/closed)
        $openStatuses = implode(',', [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING]);

        $res = $DB->doQuery(
            "SELECT e.tickets_id, e.keywords_json, c.completename AS category_name
             FROM `$embeddingTable` e
             INNER JOIN `$ticketTable` t ON t.id = e.tickets_id
             LEFT JOIN `$categoryTable` c ON c.id = t.itilcategories_id
             WHERE t.date_creation >= '$since'
               AND t.status IN ($openStatuses)
               AND t.is_deleted = 0"
        );

        $clusters = [];
        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $keywords = json_decode($row['keywords_json'], true) ?? [];
            $category = $row['category_name'] ?? 'sem_categoria';

            // Cluster key: top-3 keywords + category (normalized)
            sort($keywords);
            $topKeywords = array_slice($keywords, 0, 3);
            $clusterKey  = md5(implode('|', $topKeywords) . '|' . mb_strtolower($category));

            if (!isset($clusters[$clusterKey])) {
                $clusters[$clusterKey] = [
                    'keywords'   => $topKeywords,
                    'category'   => $category,
                    'ticket_ids' => [],
                ];
            }
            $clusters[$clusterKey]['ticket_ids'][] = (int) $row['tickets_id'];
        }

        return $clusters;
    }

    // ── Problem creation ──────────────────────────────────────────────────────

    private function createProblem(array $cluster): ?int
    {
        global $SESSION;

        $keywords = implode(', ', $cluster['keywords']);
        $category = $cluster['category'];
        $count    = count($cluster['ticket_ids']);
        $ids      = implode(', #', $cluster['ticket_ids']);

        $name = sprintf(
            '[IA] Incidente recorrente: %s (%s)',
            ucfirst($keywords),
            $category
        );

        $content  = "<p><strong>Padrao de incidente recorrente detectado automaticamente pelo Agente IA.</strong></p>";
        $content .= "<ul>";
        $content .= "<li><strong>Categoria:</strong> {$category}</li>";
        $content .= "<li><strong>Palavras-chave:</strong> {$keywords}</li>";
        $content .= "<li><strong>Chamados relacionados:</strong> #{$ids}</li>";
        $content .= "<li><strong>Total de ocorrencias:</strong> {$count}</li>";
        $content .= "</ul>";
        $content .= "<p>Investigue a causa raiz para evitar recorrencias.</p>";

        $entities_id = $_SESSION['glpiactive_entity'] ?? 0;

        $problem = new \Problem();
        $newId   = $problem->add([
            'name'             => $name,
            'content'          => $content,
            'status'           => \Problem::INCOMING,
            'urgency'          => 3,
            'impact'           => 3,
            'priority'         => 3,
            'entities_id'      => $entities_id,
            'is_recursive'     => 0,
            'users_id_lastupdater' => 0,
        ]);

        return $newId ? (int) $newId : null;
    }

    private function linkTicketsToProblem(int $problemId, array $ticketIds): void
    {
        $pt = new \Problem_Ticket();
        foreach ($ticketIds as $ticketId) {
            // Avoid duplicate links
            $existing = $pt->find([
                'problems_id' => $problemId,
                'tickets_id'  => $ticketId,
            ]);
            if (empty($existing)) {
                $pt->add([
                    'problems_id' => $problemId,
                    'tickets_id'  => $ticketId,
                ]);
            }
        }
    }

    // ── Cluster persistence ───────────────────────────────────────────────────

    private function getExistingCluster(string $key): ?array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'problems_id', 'incident_count'],
            'FROM'   => 'glpi_plugin_agentassistant_clusters',
            'WHERE'  => ['cluster_key' => $key],
            'LIMIT'  => 1,
        ]);

        return $row->count() > 0 ? $row->current() : null;
    }

    private function saveCluster(string $key, array $cluster, int $problemId, ?int $existingId): void
    {
        global $DB;

        $data = [
            'cluster_key'    => $key,
            'category_name'  => substr($cluster['category'], 0, 255),
            'keywords_json'  => json_encode($cluster['keywords']),
            'incident_ids'   => json_encode($cluster['ticket_ids']),
            'incident_count' => count($cluster['ticket_ids']),
            'problems_id'    => $problemId,
            'last_seen'      => date('Y-m-d H:i:s'),
            'is_active'      => 1,
        ];

        if ($existingId !== null) {
            $DB->update('glpi_plugin_agentassistant_clusters', $data, ['id' => $existingId]);
        } else {
            $data['first_seen'] = date('Y-m-d H:i:s');
            $DB->insert('glpi_plugin_agentassistant_clusters', $data);
        }
    }

    private function updateCluster(int $id, array $cluster): void
    {
        global $DB;

        $DB->update('glpi_plugin_agentassistant_clusters', [
            'incident_ids'   => json_encode($cluster['ticket_ids']),
            'incident_count' => count($cluster['ticket_ids']),
            'last_seen'      => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }
}
