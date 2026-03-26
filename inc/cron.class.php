<?php

use GlpiPlugin\Agentassistant\SuggestionEngine;
use GlpiPlugin\Agentassistant\RecurrenceDetector;
use GlpiPlugin\Agentassistant\Config;

/**
 * PluginAgentassistantCron — Cron task definitions + runners.
 *
 * Tasks:
 *  - agentassistantProcessQueue  (every 5 min)  — process ticket analysis queue
 *  - agentassistantDetectRecurrences (every 2 h) — detect recurring incidents
 */
class PluginAgentassistantCron extends CommonDBTM
{
    // ── Task registration ─────────────────────────────────────────────────────

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'agentassistantProcessQueue'      => ['description' => 'Agent Assistant: processar fila de analise de chamados'],
            'agentassistantDetectRecurrences' => ['description' => 'Agent Assistant: detectar incidentes recorrentes'],
            default                           => [],
        };
    }

    // ── Queue processor ───────────────────────────────────────────────────────

    /**
     * Process up to 20 pending queue entries per run.
     */
    public static function cronAgentassistantProcessQueue(CronTask $task): int
    {
        global $DB;

        if (!Config::getBool('enabled')) {
            return 0;
        }

        $rows = $DB->request([
            'SELECT'  => ['id', 'tickets_id', 'attempts'],
            'FROM'    => 'glpi_plugin_agentassistant_queue',
            'WHERE'   => ['operation' => 'analyze', ['attempts' => ['<', 3]]],
            'ORDER'   => ['priority ASC', 'date_scheduled ASC'],
            'LIMIT'   => 20,
        ]);

        $engine   = new SuggestionEngine();
        $processed = 0;

        foreach ($rows as $row) {
            $queueId  = (int) $row['id'];
            $ticketId = (int) $row['tickets_id'];

            try {
                $result = $engine->analyze($ticketId);

                // Remove from queue on success
                $DB->delete('glpi_plugin_agentassistant_queue', ['id' => $queueId]);
                $processed++;
            } catch (\Throwable $e) {
                // Increment attempt counter; will be retried next run
                $DB->update('glpi_plugin_agentassistant_queue', [
                    'attempts' => $row['attempts'] + 1,
                ], ['id' => $queueId]);
            }

            $task->addVolume(1);
        }

        return $processed > 0 ? 1 : 0;
    }

    // ── Recurrence detector ───────────────────────────────────────────────────

    public static function cronAgentassistantDetectRecurrences(CronTask $task): int
    {
        if (!Config::getBool('enabled')) {
            return 0;
        }

        $detector = new RecurrenceDetector();
        $created  = $detector->run();

        $task->addVolume($created);
        return $created > 0 ? 1 : 0;
    }

    // ── GLPI cron registration ────────────────────────────────────────────────

    /**
     * Called by GLPI during plugin init to register cron tasks.
     */
    public static function install(): void
    {
        CronTask::register(
            'PluginAgentassistantCron',
            'agentassistantProcessQueue',
            5 * MINUTE_TIMESTAMP,
            [
                'comment'  => 'Agent Assistant: fila de analise',
                'mode'     => CronTask::MODE_INTERNAL,
                'state'    => CronTask::STATE_WAITING,
                'hourlyto' => 0,
            ]
        );

        CronTask::register(
            'PluginAgentassistantCron',
            'agentassistantDetectRecurrences',
            2 * HOUR_TIMESTAMP,
            [
                'comment'  => 'Agent Assistant: deteccao de recorrencia',
                'mode'     => CronTask::MODE_INTERNAL,
                'state'    => CronTask::STATE_WAITING,
                'hourlyto' => 0,
            ]
        );
    }

    /**
     * Called by GLPI during plugin uninstall.
     */
    public static function uninstall(): void
    {
        CronTask::unregister('PluginAgentassistantCron');
    }
}
