<?php
/**
 * Remote Sync config.
 *
 * Copy this file to config/remote-sync.php in your Craft project to customise it.
 */

use craft\helpers\App;

return [

    // Remote environments, keyed by name. Each needs a host (SSH connection
    // string, e.g. 'forge@example.com' or 'forge@example.com:2222') and a path
    // (the Craft app root on the server). A /current atomic-deploy layout is
    // detected automatically. Set php_binary to pin a specific binary and skip
    // auto-detection. Pushing is disabled per remote unless push is true.
    'remotes' => [
        // 'production' => [
        //     'host' => App::env('REMOTE_SYNC_PRODUCTION_HOST'),
        //     'path' => App::env('REMOTE_SYNC_PRODUCTION_PATH'),
        //     'push' => (bool) App::env('REMOTE_SYNC_PRODUCTION_PUSH'),
        //     // 'php_binary' => '/usr/bin/php8.4',
        // ],
    ],

    // Used when no remote is given and more than one remote is configured.
    'default' => App::env('REMOTE_SYNC_DEFAULT'),

    // Paths relative to storage/ synced by the files scope.
    'paths' => [
        'app',
    ],

    // Extra rsync exclude patterns (e.g. 'cache', '*.log', 'temp/**').
    // Dotfiles are always excluded automatically.
    'exclude_paths' => [
        'craft-transforms',
    ],

    // Pull and push refuse to run when the local Craft environment is
    // production. Set to true if this machine is intentionally a sync source or
    // target while running in production.
    'allow_production' => false,

    // Timeouts in seconds. 'remote' covers short remote commands (probing,
    // listing and deleting snapshots, craft up). 'transfer' covers data-sized
    // operations (backup create/restore and rsync transfers).
    'timeouts' => [
        'remote' => 300,
        'transfer' => 1800,
    ],
];
