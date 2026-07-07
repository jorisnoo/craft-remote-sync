# Upgrading from 2.x

The internals were rewritten into a layered architecture, and two commands were
added (`doctor`, `prune`). The command names (`remote-sync/pull`,
`remote-sync/push`) are unchanged, but the config schema and some behavior changed.

## Config changes

Update `config/remote-sync.php`:

| 2.x | Now |
|---|---|
| `pushAllowed => true` | `push => true` |
| `timeouts.createSnapshot`, `timeouts.download`, `timeouts.upload`, `timeouts.fileSync` | `timeouts.remote` (short commands) and `timeouts.transfer` (data transfers) |
| env vars `REMOTE_SYNC_HOST`, `REMOTE_SYNC_PATH`, `REMOTE_SYNC_PUSH_ALLOWED` | per-remote `REMOTE_SYNC_{REMOTE}_{KEY}`, e.g. `REMOTE_SYNC_PRODUCTION_HOST` |

New keys:

- `default` — the remote used when none is given and more than one is configured.
- `allow_production` — pull and push refuse to run in a production environment unless this is `true`.
- `php_binary` (per remote, optional) — pin a PHP binary and skip auto-detection.

The default `paths` is now `['app']` and `exclude_paths` is `['craft-transforms']`. Placeholder hosts (`your-server`, `example.com`) are rejected, so replace the examples with real values.

## Command changes

- Scope is chosen with `--database` and/or `--files`, or interactively (multi-select). The previous `database` / `files` / `both` prompt is gone.
- New flags: `--no-backup`, `--keep-snapshot` (pull only), `--delete`, `--path=a,b`, `--dry-run`.
- File deletion is opt-in. Rsync no longer deletes local or remote files unless you pass `--delete`; the plan preview shows what it would remove.
- `push` now always asks for a typed `yes`, requires the remote's `push` to be `true`, and needs an explicit `--database`/`--files` scope when run non-interactively.
- The ad-hoc `push --remoteHost=... --remotePath=...` remote was removed. Define the target in `config/remote-sync.php` instead.
- Production is guarded by `allow_production`. The previous behavior of hiding the remote whose name matched the current environment was removed.

## New commands

- `craft remote-sync/doctor [remote]` — checks local tools and, per remote, the host key, application path, PHP binary, and rsync.
- `craft remote-sync/prune [remote] [--local] [--remote] [--keep=5] [--all] [--dry-run]` — deletes old snapshots created by this module.

## Programmatic API

The `remoteSyncService` module component and `Module::getRemoteSyncService()` were
removed. If you called them directly, use the classes under
`Noo\CraftRemoteSync\Remotes`, `Snapshots`, and `Sync` instead.
