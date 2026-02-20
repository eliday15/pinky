<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ZKTeco Database Configuration
    |--------------------------------------------------------------------------
    |
    | Database connection settings for the ZKTeco attendance system.
    |
    */

    'database' => [
        'host' => env('ZKTECO_DB_HOST', '107.152.45.249'),
        'port' => env('ZKTECO_DB_PORT', 5435),
        'database' => env('ZKTECO_DB_NAME', 'default'),
        'username' => env('ZKTECO_DB_USER', 'mysql'),
        'password' => env('ZKTECO_DB_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for syncing attendance data from ZKTeco devices.
    |
    */

    'sync' => [
        // When true, the Python sync runs on a local office PC (agent mode)
        // instead of being invoked by Laravel directly.
        'remote_python' => env('ZKTECO_REMOTE_PYTHON', false),

        // Shared secret the Python agent uses to authenticate with the API
        'agent_key' => env('ZKTECO_AGENT_KEY', ''),

        // Sync interval in hours
        'interval_hours' => 4,

        // Punch type mappings (from ZKTeco)
        'punch_types' => [
            0 => 'check_in',
            1 => 'check_out',
        ],

        // Authentication method mappings
        'auth_methods' => [
            0 => 'fingerprint',
            1 => 'password',
            12 => 'other',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Device Configuration
    |--------------------------------------------------------------------------
    |
    | Known ZKTeco devices in the network.
    |
    */

    'devices' => [
        [
            'name' => 'K40-1',
            'ip' => '192.168.1.11',
        ],
        [
            'name' => 'K40-2',
            'ip' => '192.168.1.12',
        ],
        [
            'name' => 'E9-1',
            'ip' => '192.168.1.13',
        ],
        [
            'name' => 'E9-2',
            'ip' => '192.168.1.14',
        ],
    ],
];
