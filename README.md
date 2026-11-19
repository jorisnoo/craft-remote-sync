# Remote Sync for Craft CMS

A Craft CMS module that syncs databases and storage files between remote and local environments over SSH and rsync.

![Craft CMS 5](https://img.shields.io/badge/Craft%20CMS-5.x-e5422b.svg)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb3.svg)
![License](https://img.shields.io/badge/license-MIT-blue.svg)

---

## Features

- Pull a remote database and storage files to your local environment
- Push your local database and files to a remote (disabled per remote by default)
- A plan preview before anything is changed, with a `--dry-run` that stops after it
- Backups taken automatically before either side's database is overwritten
- A single SSH round-trip to detect the atomic layout, a compatible PHP binary, and rsync
- File deletion is opt-in: the preview shows what `--delete` would remove, but nothing is deleted unless you ask
- `doctor` to check prerequisites and `prune` to clean up old snapshots
- Support for multiple named remotes and atomic deployments (`current/` symlink)

---

## Requirements

| | Version |
|---|---|
| Craft CMS | 5.x |
| PHP | 8.2+ |
| Local tools | OpenSSH (`ssh`, `ssh-keyscan`, `ssh-keygen`), `rsync` (including stock macOS openrsync), and the database client/dump tools (`mysql`+`mysqldump` or `psql`+`pg_dump`) |
| Remote tools | SSH, `rsync`, the same database client/dump tools for your database driver, and a PHP binary that satisfies the project. If the default `php` does not, expose a versioned binary such as `php8.4` on `PATH`, or pin one with `php_binary`. A rejected pin stops the sync. |

Run `craft remote-sync/doctor` to check everything is in place.

The file preview uses `--itemize-changes` and transfers use `--progress`; rsync 3.1 is not required. For interactive enrollment of a new SSH host, use OpenSSH 8.4+ and compare the displayed SHA-256 fingerprint to one obtained independently from the server administrator. SSH aliases and explicit ports are resolved before scanning. For older clients, jump hosts or inaccessible key scans, verify and provision the host in `known_hosts` before running the sync.

---

## Installation

```bash
composer require jorisnoo/craft-remote-sync
```

Register it as a module in `config/app.php`:

```php
return [
    'modules' => [
        'remote-sync' => \Noo\CraftRemoteSync\Module::class,
    ],
    'bootstrap' => ['remote-sync'],
];
```

The `bootstrap` entry is required so the module registers its console controllers.

---

## Configuration

Copy the config file into your project and edit it:

```bash
cp vendor/jorisnoo/craft-remote-sync/src/config.php config/remote-sync.php
```

```php
<?php

use craft\helpers\App;

return [
    'remotes' => [
        'production' => [
            'host' => App::env('REMOTE_SYNC_PRODUCTION_HOST'),
            'path' => App::env('REMOTE_SYNC_PRODUCTION_PATH'),
            'push' => (bool) App::env('REMOTE_SYNC_PRODUCTION_PUSH'),
            // 'php_binary' => '/usr/bin/php8.4',
        ],
    ],

    'default' => App::env('REMOTE_SYNC_DEFAULT'),

    'paths' => ['app'],

    'exclude_paths' => ['craft-transforms'],

    'allow_production' => false,

    'timeouts' => [
        'remote' => 300,
        'transfer' => 1800,
    ],
];
```

Each remote needs a `host` (an SSH connection string, optionally `user@host:port`) and a `path` (the Craft app root on the server). For an atomic deployment, point `path` at the release parent; a `/current` symlink is detected and used automatically. Pushing is refused unless `push` is `true`.

The env var names follow `REMOTE_SYNC_{REMOTE}_{KEY}`, so a `staging` remote reads `REMOTE_SYNC_STAGING_HOST` and `REMOTE_SYNC_STAGING_PATH`.

```dotenv
REMOTE_SYNC_PRODUCTION_HOST=deploy@example.com
REMOTE_SYNC_PRODUCTION_PATH=/var/www/html
REMOTE_SYNC_PRODUCTION_PUSH=false
REMOTE_SYNC_DEFAULT=production
```

---

## Usage

### Pull (remote → local)

```bash
craft remote-sync/pull [remote] [--database] [--files] [--no-backup] [--discard-snapshot] [--delete] [--path=app,uploads] [--dry-run] [--force]
```

Without flags it asks which remote and what to sync, verifies the host key, probes the remote, prints the plan, and asks for confirmation. For the database it backs up your local database (`pre-pull-*`), takes a snapshot on the remote, downloads and restores it, then runs `craft up` and clears caches. For files it rsyncs each configured path.

Missing source directories are reported and skipped, not treated as empty directories for `--delete`. `storage/backups` and its descendants cannot be configured as file sync paths.

### Push (local → remote)

```bash
craft remote-sync/push [remote] [--database] [--files] [--no-backup] [--delete] [--path=app,uploads] [--dry-run] [--force]
```

Push requires the remote to have `push` enabled, asks for a typed `yes` confirmation unless `--force` is supplied, and needs an explicit scope when run non-interactively. It backs up the remote database (`pre-push-*`) before uploading and restoring your local one, then runs `craft up` on the remote.

### Doctor

```bash
craft remote-sync/doctor [remote]
```

Checks local tools and, per remote, the host key, application path, PHP binary, rsync, database tools, and whether the backup directory is writable or can be created.

### Prune

```bash
craft remote-sync/prune [remote] [--local] [--remote] [--keep=5] [--all] [--dry-run] [--force]
```

`--keep=N` keeps the newest N of each type (`remote-sync-*`, `pre-pull-*`, `pre-push-*`) on each side. N must be a non-negative integer. Other snapshots are left alone unless `--all` is supplied, in which case they have a separate retention budget. Use `--dry-run` to inspect the selection.

---

## Backups and snapshots

Two kinds of database file are involved:

- A **transfer snapshot** (`remote-sync-*`) is the data being moved. The module attempts to remove the remote copy even if a transfer fails. With PHP's `pcntl` extension it also attempts cleanup on Ctrl+C, hangup and termination. If SSH is unavailable, the process is killed forcibly, or the machine loses power, inspect `storage/backups` and prune leftovers. A successful pull keeps the local download by default.
- A **backup** (`pre-pull-*` locally, `pre-push-*` on the remote) is a safety copy taken before a side is overwritten. It is kept, so you can roll back with `craft db/restore`.

Both live in Craft's standard `storage/backups` directory. Skip the safety backup with `--no-backup`, and delete the downloaded snapshot once it has been imported with `--discard-snapshot`. On import failure the download is retained for recovery. Prune keeps the newest five per type by default.

A push cleans up both copies of its transfer snapshot, local and remote. Only a pull leaves one behind.

A pull or push refuses to run when the local environment is `production`, unless you set `allow_production` to `true`.

---

## Versioning

This module is maintained on separate branches for each major Craft CMS version:

| Branch | Craft CMS | PHP |
|---|---|---|
| `main` | 5.x | 8.2+ |
| `craft-4` | 4.x | 8.1+ |

```bash
# Craft 5
composer require jorisnoo/craft-remote-sync

# Craft 4
composer require jorisnoo/craft-remote-sync:dev-craft-4
```

Upgrading from 2.0.0? See [UPGRADING.md](UPGRADING.md) for the config and command changes.

---

## License

MIT — see [LICENSE](LICENSE.md) for details.

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
