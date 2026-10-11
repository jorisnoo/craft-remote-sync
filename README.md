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
| Local tools | `ssh`, `rsync`, and the database client/dump tools (`mysql`+`mysqldump` or `psql`+`pg_dump`) |
| Remote tools | `ssh`, `rsync`, and a PHP binary that satisfies the project. If the default `php` does not, expose a versioned binary such as `php8.4` on `PATH` and it is detected automatically, or pin one with `php_binary`. |

Run `craft remote-sync/doctor` to check everything is in place.

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

Each remote needs a `host` (an SSH connection string, optionally `user@host:port`; neither part may start with `-`) and a `path` (the Craft app root on the server). For an atomic deployment, point `path` at the release parent; a `/current` symlink is detected and used automatically. Pushing is refused unless `push` is `true`.

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

### Push (local → remote)

```bash
craft remote-sync/push [remote] [--database] [--files] [--no-backup] [--delete] [--path=app,uploads] [--dry-run] [--force]
```

Push requires the remote to have `push` enabled, always asks for a typed `yes` confirmation, and needs an explicit scope when run non-interactively. It backs up the remote database (`pre-push-*`) before uploading and restoring your local one, then runs `craft up` on the remote.

### Doctor

```bash
craft remote-sync/doctor [remote]
```

Checks local tools and, per remote, the host key, application path, PHP binary, rsync, and whether the remote `storage/backups` directory is writable. The remote name may be abbreviated to any unambiguous prefix, as with pull and push.

### Prune

```bash
craft remote-sync/prune [remote] [--local] [--remote] [--keep=5] [--all] [--dry-run] [--force]
```

Deletes old snapshots created by this module, keeping the newest `--keep`. It leaves snapshots it did not create alone unless `--all` is given.

---

## Backups and snapshots

Two kinds of database file are involved:

- A **transfer snapshot** (`remote-sync-*`) is the data being moved. The copy on the remote is always cleaned up, even if you interrupt with Ctrl+C. On a pull, the copy downloaded to your machine is kept.
- A **backup** (`pre-pull-*` locally, `pre-push-*` on the remote) is a safety copy taken before a side is overwritten. It is kept, so you can roll back with `craft db/restore`.

Both live in Craft's standard `storage/backups` directory, which file syncs never touch: `backups` is rejected as a `--path` or `paths` entry. Skip the safety backup with `--no-backup`, and delete the downloaded snapshot once it has been imported with `--discard-snapshot`. Kept snapshots are subject to `remote-sync/prune`, which keeps the newest five by default.

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

Upgrading from 2.x? See [UPGRADING.md](UPGRADING.md) for the config and command changes.

---

## License

MIT — see [LICENSE](LICENSE.md) for details.

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
