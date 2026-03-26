<?php

/**
 * PluginAgentassistantMenu — GLPI admin menu entry.
 */
class PluginAgentassistantMenu extends CommonGLPI
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return 'Agent Assistant';
    }

    public static function getMenuContent(): array
    {
        $base = Plugin::getWebDir('agentassistant', true);

        return [
            'title'   => 'Agent Assistant',
            'page'    => $base . '/front/config.php',
            'icon'    => 'ti ti-robot',
            'options' => [
                'config' => [
                    'title' => 'Configuracao',
                    'page'  => $base . '/front/config.php',
                    'icon'  => 'ti ti-settings',
                ],
                'logs' => [
                    'title' => 'Logs',
                    'page'  => $base . '/front/logs.php',
                    'icon'  => 'ti ti-list',
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
