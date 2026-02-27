<?php
/**
 * Remote Sync config file.
 *
 * Publish this file to config/remote-sync.php in your Craft project to customize settings.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Remote
    |--------------------------------------------------------------------------
    |
    | The name of the default remote environment to use when no remote
    | is specified. Must match one of the keys in the 'remotes' array.
    |
    */

    'default' => getenv('REMOTE_SYNC_DEFAULT') ?: 'production',

    /*
    |--------------------------------------------------------------------------
    | Remote Environments
    |--------------------------------------------------------------------------
    |
    | Each entry represents a remote environment. The key is the remote name.
    |
    | - host:        SSH connection string (e.g., 'user@example.com' or 'user@example.com:2222')
    | - path:        Absolute path to the Craft application root on the remote server
    | - pushAllowed: Whether pushing to this remote is permitted (false by default for safety)
    |
    */

    'remotes' => [
        'production' => [
            'host' => getenv('REMOTE_SYNC_HOST') ?: 'user@example.com',
            'path' => getenv('REMOTE_SYNC_PATH') ?: '/var/www/html',
            'pushAllowed' => (bool) (getenv('REMOTE_SYNC_PUSH_ALLOWED') ?: false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Paths
    |--------------------------------------------------------------------------
    |
    | An array of subdirectories within the Craft storage/ directory to sync.
    | Paths are relative to the storage/ directory (e.g., 'rebrand', 'uploads').
    |
    */

    'paths' => [
        // 'rebrand',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Timeout values in seconds for each operation.
    |
    */

    'timeouts' => [
        'createSnapshot' => 300,
        'download' => 300,
        'upload' => 300,
        'fileSync' => 300,
    ],

];
