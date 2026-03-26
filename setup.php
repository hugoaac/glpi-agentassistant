<?php

/**
 * agentassistant – AI assistant for IT technicians  v1.0.0
 *
 * Features:
 *  - Automatic ticket analysis (TF-IDF embeddings)
 *  - Similarity search with confidence scoring
 *  - AI fallback via Claude API
 *  - Private followup suggestions
 *  - Learning system (weight feedback)
 *  - Recurring incident detection → auto Problem creation
 *  - Admin configuration
 */

define('PLUGIN_AGENTASSISTANT_VERSION', '1.0.0');
define('PLUGIN_AGENTASSISTANT_MIN_GLPI', '11.0.0');

// ── Autoload ──────────────────────────────────────────────────────────────────

spl_autoload_register(function (string $class): void {
    $prefix = 'GlpiPlugin\\Agentassistant\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Plugin metadata ───────────────────────────────────────────────────────────

function plugin_version_agentassistant(): array
{
    return [
        'name'         => 'Agent Assistant',
        'version'      => PLUGIN_AGENTASSISTANT_VERSION,
        'author'       => 'Hugo',
        'license'      => 'GPL v2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_AGENTASSISTANT_MIN_GLPI],
        ],
    ];
}

function plugin_agentassistant_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_AGENTASSISTANT_MIN_GLPI, '<')) {
        echo 'This plugin requires GLPI >= ' . PLUGIN_AGENTASSISTANT_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_agentassistant_check_config(): bool
{
    return true;
}

// ── Init ──────────────────────────────────────────────────────────────────────

function plugin_init_agentassistant(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['agentassistant'] = true;

    if (!Session::getLoginUserID()) {
        return;
    }

    // ── Ticket hooks (analysis trigger) ──────────────────────────────────
    $PLUGIN_HOOKS['item_add']['agentassistant']    = 'plugin_agentassistant_item_add';
    $PLUGIN_HOOKS['item_update']['agentassistant'] = 'plugin_agentassistant_item_update';

    // ── Menu Configurar ───────────────────────────────────────────────────
    $PLUGIN_HOOKS['menu_toadd']['agentassistant'] = [
        'config' => 'PluginAgentassistantMenu',
    ];

    // ── Cron ──────────────────────────────────────────────────────────────
    $PLUGIN_HOOKS['cron']['agentassistant'] = 'PluginAgentassistantCron';

    // ── CSS / JS: injetar apenas em páginas de ticket ─────────────────────
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $isTicketPage = (
        strpos($uri, '/Ticket') !== false
        || strpos($uri, 'ticket.form') !== false
    );

    // Só para técnicos (interface central)
    if ($isTicketPage && Session::getCurrentInterface() === 'central') {
        $PLUGIN_HOOKS['add_css']['agentassistant'] = [
            'public/css/agent-assistant.css',
        ];
        $PLUGIN_HOOKS['add_javascript']['agentassistant'] = [
            'public/js/agent-assistant.js',
        ];
    }
}
