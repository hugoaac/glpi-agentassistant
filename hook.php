<?php

/**
 * agentassistant – Install / Uninstall / Hook callbacks
 */

// ── Install ───────────────────────────────────────────────────────────────────

function plugin_agentassistant_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    // ── glpi_plugin_agentassistant_config ─────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_config')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_config` (
                `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `config_key`   VARCHAR(100)  NOT NULL,
                `config_value` TEXT          NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `config_key` (`config_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());

        $defaults = [
            'enabled'              => '1',
            'ai_api_key'           => '',
            'ai_model'             => 'claude-sonnet-4-6',
            'confidence_high'      => '80',
            'confidence_medium'    => '50',
            'similarity_threshold' => '0.35',
            'max_similar_tickets'  => '5',
            'auto_followup'        => '1',
            'auto_problem'         => '1',
            'recurrence_count'     => '10',
            'recurrence_days'      => '5',
            'max_tokens'           => '800',
        ];
        foreach ($defaults as $key => $value) {
            $DB->insert('glpi_plugin_agentassistant_config', [
                'config_key'   => $key,
                'config_value' => $value,
            ]);
        }
    }

    // ── glpi_plugin_agentassistant_queue ──────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_queue')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_queue` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tickets_id`     INT UNSIGNED NOT NULL,
                `operation`      VARCHAR(30)  NOT NULL DEFAULT 'analyze',
                `priority`       TINYINT      NOT NULL DEFAULT 5,
                `attempts`       TINYINT      NOT NULL DEFAULT 0,
                `date_scheduled` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tickets_operation` (`tickets_id`, `operation`),
                KEY `date_scheduled` (`date_scheduled`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    // ── glpi_plugin_agentassistant_embeddings ─────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_embeddings')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_embeddings` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tickets_id`     INT UNSIGNED NOT NULL,
                `checksum`       VARCHAR(32)  NOT NULL,
                `embedding_json` MEDIUMTEXT   NOT NULL,
                `keywords_json`  TEXT         NOT NULL,
                `date_creation`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `tickets_id` (`tickets_id`),
                KEY `checksum` (`checksum`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    // ── glpi_plugin_agentassistant_suggestions ────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_suggestions')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_suggestions` (
                `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `tickets_id`       INT UNSIGNED     NOT NULL,
                `confidence_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `source_type`      ENUM('similar','ai','hybrid') NOT NULL DEFAULT 'similar',
                `source_ids`       TEXT,
                `suggestion_text`  LONGTEXT         NOT NULL,
                `explanation`      TEXT,
                `followup_id`      INT UNSIGNED     DEFAULT NULL,
                `status`           ENUM('pending','shown','used','dismissed') NOT NULL DEFAULT 'pending',
                `date_creation`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    // ── glpi_plugin_agentassistant_learning ───────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_learning')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_learning` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `suggestion_id` INT UNSIGNED NOT NULL,
                `tickets_id`    INT UNSIGNED NOT NULL,
                `users_id`      INT UNSIGNED NOT NULL DEFAULT 0,
                `action`        ENUM('used','dismissed','ignored') NOT NULL DEFAULT 'ignored',
                `source_hash`   VARCHAR(32)  NOT NULL DEFAULT '',
                `weight_delta`  FLOAT        NOT NULL DEFAULT 0,
                `date_action`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `suggestion_id` (`suggestion_id`),
                KEY `source_hash` (`source_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    // ── glpi_plugin_agentassistant_clusters ───────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_clusters')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_clusters` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cluster_key`    VARCHAR(32)  NOT NULL,
                `category_name`  VARCHAR(255) NOT NULL DEFAULT '',
                `keywords_json`  TEXT         NOT NULL,
                `incident_ids`   MEDIUMTEXT   NOT NULL,
                `incident_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `problems_id`    INT UNSIGNED DEFAULT NULL,
                `first_seen`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_seen`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `cluster_key` (`cluster_key`),
                KEY `last_seen` (`last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    // ── glpi_plugin_agentassistant_logs ───────────────────────────────────
    if (!$DB->tableExists('glpi_plugin_agentassistant_logs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_agentassistant_logs` (
                `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
                `tickets_id`       INT UNSIGNED     NOT NULL DEFAULT 0,
                `operation`        VARCHAR(50)      NOT NULL DEFAULT '',
                `confidence_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `source_type`      VARCHAR(20)      NOT NULL DEFAULT '',
                `tokens_used`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `duration_ms`      INT UNSIGNED     NOT NULL DEFAULT 0,
                `message`          TEXT,
                `date_creation`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}
        ") or die($DB->error());
    }

    PluginAgentassistantCron::install();

    return true;
}

// ── Uninstall ─────────────────────────────────────────────────────────────────

function plugin_agentassistant_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_agentassistant_logs',
        'glpi_plugin_agentassistant_clusters',
        'glpi_plugin_agentassistant_learning',
        'glpi_plugin_agentassistant_suggestions',
        'glpi_plugin_agentassistant_embeddings',
        'glpi_plugin_agentassistant_queue',
        'glpi_plugin_agentassistant_config',
    ];

    foreach ($tables as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `$table`");
    }

    return true;
}

// ── Hook callbacks ────────────────────────────────────────────────────────────

function plugin_agentassistant_item_add(CommonDBTM $item): void
{
    if (!($item instanceof Ticket)) {
        return;
    }
    _agentassistant_enqueue((int) $item->getID(), 3);
}

function plugin_agentassistant_item_update(CommonDBTM $item): void
{
    if (!($item instanceof Ticket)) {
        return;
    }
    $changed = $item->updates ?? [];
    if (empty(array_intersect($changed, ['name', 'content', 'itilcategories_id']))) {
        return;
    }
    _agentassistant_enqueue((int) $item->getID(), 5);
}

function _agentassistant_enqueue(int $ticketId, int $priority = 5): void
{
    global $DB;

    require_once __DIR__ . '/src/Config.php';
    if (!\GlpiPlugin\Agentassistant\Config::get('enabled')) {
        return;
    }

    $DB->doQuery(
        "INSERT INTO `glpi_plugin_agentassistant_queue`
            (`tickets_id`, `operation`, `priority`, `attempts`, `date_scheduled`)
         VALUES ($ticketId, 'analyze', $priority, 0, NOW())
         ON DUPLICATE KEY UPDATE
            `priority`       = LEAST(`priority`, $priority),
            `date_scheduled` = NOW()"
    );
}
