<?php

namespace GlpiPlugin\Agentassistant;

/**
 * Config — read/write plugin configuration from DB.
 */
class Config
{
    /** @var array<string,string> In-memory cache for current request */
    private static array $cache = [];

    // ── Defaults (mirrors hook.php installer) ─────────────────────────────────

    private static array $defaults = [
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

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Get a config value. Returns the default if not set.
     * Casts to bool when the key is a flag (value '0'/'1').
     */
    public static function get(string $key): string
    {
        if (!isset(self::$cache[$key])) {
            self::loadAll();
        }
        return self::$cache[$key] ?? (self::$defaults[$key] ?? '');
    }

    public static function getInt(string $key): int
    {
        return (int) self::get($key);
    }

    public static function getFloat(string $key): float
    {
        return (float) self::get($key);
    }

    public static function getBool(string $key): bool
    {
        return self::get($key) === '1';
    }

    /**
     * Save a config value to the DB and update the in-memory cache.
     */
    public static function set(string $key, string $value): void
    {
        global $DB;

        self::$cache[$key] = $value;

        $DB->doQuery(
            "INSERT INTO `glpi_plugin_agentassistant_config` (`config_key`, `config_value`)
             VALUES ('" . $DB->escape($key) . "', '" . $DB->escape($value) . "')
             ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`)"
        );
    }

    /**
     * Save multiple values at once (used by the config form).
     *
     * @param array<string,string> $data
     */
    public static function setMany(array $data): void
    {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, self::$defaults)) {
                self::set($key, (string) $value);
            }
        }
    }

    /** Retrieve all config rows from DB into the static cache. */
    public static function loadAll(): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_agentassistant_config')) {
            self::$cache = self::$defaults;
            return;
        }

        self::$cache = self::$defaults; // seed with defaults

        $rows = $DB->request(['FROM' => 'glpi_plugin_agentassistant_config']);
        foreach ($rows as $row) {
            self::$cache[$row['config_key']] = $row['config_value'];
        }
    }

    /** Return all key-value pairs (for the config form). */
    public static function getAll(): array
    {
        if (empty(self::$cache)) {
            self::loadAll();
        }
        return self::$cache;
    }
}
