<?php

return [
    'enabled' => env('DRIVE_SYNC_ENABLED', true),

    'php_cli_binary' => env('PHP_CLI_BINARY'),

    'notify' => env('DRIVE_SYNC_NOTIFY', true),

    'notify_roles' => array_values(array_filter(array_map(
        static fn (string $value): string => trim(strtolower($value)),
        explode(',', (string) env('DRIVE_SYNC_NOTIFY_ROLES', 'administrador,rector'))
    ))),

    'bootstrap_on_empty_state' => env('DRIVE_SYNC_BOOTSTRAP_ON_EMPTY_STATE', true),

    'mail_top_items' => (int) env('DRIVE_SYNC_MAIL_TOP_ITEMS', 10),

    'dashboard_top_items' => (int) env('DRIVE_SYNC_DASHBOARD_TOP_ITEMS', 5),
];
