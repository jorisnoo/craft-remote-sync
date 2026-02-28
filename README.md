# Remote Sync for Craft CMS

A Craft CMS module that syncs databases and storage files between remote and local environments over SSH/rsync.

![Craft CMS 4](https://img.shields.io/badge/Craft%20CMS-4.x-e5422b.svg)
![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb3.svg)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

---

## Features

- Pull a remote database to your local environment with a single command
- Push your local database to a remote environment
- Sync storage subdirectories (uploads, rebrand, etc.) via rsync
- Interactive CLI with dry-run preview before any destructive operation
- Automatic safety backups before overwriting either environment's database
- Support for multiple named remotes (production, staging, etc.)
- Atomic deployment support (`current/` symlink pattern)
- Push protection — remotes are push-disabled by default

---

## Requirements

| | Version |
|---|---|
| Craft CMS | 4.x |
| PHP | 8.0+ |
| Server tools | `ssh`, `rsync`, `mysqldump` / `pg_dump` on both local and remote |

---

## Installation

```bash
composer require jorisnoo/craft-remote-sync
```

Then add it to your `config/app.php`:

```php
return [
    'modules' => [
        'remote-sync' => \jorge\craftremotesync\Module::class,
    ],
    'bootstrap' => ['remote-sync'],
];
```

The `bootstrap` entry is required so the module registers its console controllers on every request.

---

## Configuration

Publish the config file to your Craft project:

```bash
cp vendor/jorisnoo/craft-remote-sync/src/config.php config/remote-sync.php
```

Example `config/remote-sync.php`:

```php
<?php

return [

    /*
     * The default remote to use when none is specified.
     * Must match a key in the 'remotes' array below.
     */
    'default' => getenv('REMOTE_SYNC_DEFAULT') ?: 'production',

    /*
     * Remote environments.
     *
     *   host        — SSH connection string, optionally with port (user@host or user@host:port)
     *   path        — Absolute path to the Craft application root on the remote server.
     *                 For atomic deployments, point to the release parent — the module will
     *                 automatically append /current when isAtomic is detected.
     *   pushAllowed — Set to true to permit pushing to this remote. Defaults to false.
     */
    'remotes' => [
        'production' => [
            'host'        => getenv('REMOTE_SYNC_HOST') ?: 'user@example.com',
            'path'        => getenv('REMOTE_SYNC_PATH') ?: '/var/www/html',
            'pushAllowed' => (bool) (getenv('REMOTE_SYNC_PUSH_ALLOWED') ?: false),
        ],
        // 'staging' => [
        //     'host'        => 'user@staging.example.com',
        //     'path'        => '/var/www/staging',
        //     'pushAllowed' => true,
        // ],
    ],

    /*
     * Storage subdirectories to sync via rsync.
     * Paths are relative to the storage/ directory.
     */
    'paths' => [
        // 'rebrand',
        // 'uploads',
    ],

    /*
     * Timeout values in seconds for each operation.
     */
    'timeouts' => [
        'createSnapshot' => 300,
        'download'       => 300,
        'upload'         => 300,
        'fileSync'       => 300,
    ],

];
```

### Environment variables

All sensitive values can be provided via environment variables in your `.env` file:

```dotenv
REMOTE_SYNC_DEFAULT=production
REMOTE_SYNC_HOST=deploy@example.com
REMOTE_SYNC_PATH=/var/www/html
REMOTE_SYNC_PUSH_ALLOWED=false
```

---

## Usage

### Pull (remote → local)

```bash
php craft remote-sync/pull
```

The command will:

1. Ask which remote to use (defaults to the configured `default`)
2. Detect whether the remote uses an atomic deployment layout
3. Ask which operation to perform: `database`, `files`, or `both`
4. For **database**: show a preview, create a local safety backup, pull and restore the remote database
5. For **files**: show a rsync dry-run preview, then sync each configured storage path

### Push (local → remote)

```bash
php craft remote-sync/push
```

The command will:

1. Ask which remote to use
2. Verify that `pushAllowed` is `true` for the selected remote — aborts if not
3. Ask which operation to perform: `database`, `files`, or `both`
4. For **database**: show a preview, create a remote safety backup, upload and restore the local database
5. For **files**: show a rsync dry-run preview, then sync each configured storage path

---

## Safety

Before any destructive database operation, the module automatically creates a backup of the database being overwritten:

- **Pull**: a local backup is created before the remote database is restored locally
- **Push**: a remote backup is created before the local database is restored on the remote

Backups are stored in Craft's standard backup directory and survive the sync, so they can be used to roll back if needed.

Push operations are disabled by default. You must explicitly set `'pushAllowed' => true` in the remote's config (or via `REMOTE_SYNC_PUSH_ALLOWED=true`) before pushing to a remote.

---

## License

MIT — see [LICENSE](LICENSE) for details.
