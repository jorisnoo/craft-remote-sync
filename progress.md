## Codebase Patterns
- Console controllers: register namespace in `Plugin::init()` with `if (\Craft::$app->request->isConsoleRequest) { $this->controllerNamespace = '...'; }` — command `remote-sync/pull` comes from plugin handle + `PullController::actionIndex()`
- Craft 4 plugin: `composer.json` uses `type: craft-plugin`, `extra.handle`, `extra.class`, `extra.name`
- Craft 5 plugin: same structure, `craftcms/cms ^5.0`, `php ^8.2`; use `readonly` properties in value objects
- Namespace: `jorge\craftremotesync\` (lowercase jorge, no separators) pointing to `src/`
- Plugin class extends `craft\base\Plugin` (not just `Plugin`)
- Craft 4 plugin installer plugin: `craftcms/plugin-installer` must be allowed in `config.allow-plugins`
- Yii plugin: `yiisoft/yii2-composer` must also be allowed (Craft 4 uses Yii2)
- Console commands in Craft use `craft\console\Controller` (not `craft\base\Controller`)
- Quality check: `php -l` for syntax, `php -r "json_decode(...)"` for JSON validity
- Porting from laravel-remote-sync: Craft uses `craft db/backup --zip` and `craft db/restore <path> --drop-all-tables` instead of artisan snapshot commands
- Config in Craft: use `\Craft::$app->getConfig()->getConfigFromFile('remote-sync')` to load from `config/remote-sync.php`
- Service registration in Craft: use `$this->setComponents([...])` inside `Plugin::init()`, add typed getter `getXxx(): ServiceClass` for IDE support
- Craft environment check: use `\Craft::$app->env` (works in both Craft 4 and 5)
- PHP 8.0 target (Craft 4): do NOT use `readonly` keyword; use clone pattern for immutable updates
- PHP 8.2 target (Craft 5): use `readonly` properties; withXxx() returns `new self(named: args)` instead of clone+mutate
- SSH host format: `user@host` or `user@host:port`; parse port with regex and pass `-p PORT` to ssh, `-e 'ssh -p PORT'` to rsync
- Craft path helpers: `\Craft::$app->getPath()->getDbBackupPath()` for backup path, `getStoragePath()` for storage root
- Craft root for local commands: `\Craft::getAlias('@root') . '/craft'` as the craft executable path
- Atomic deployment: check `[ -L /path/current ]` via SSH; RemoteConfig stores `isAtomic` bool and uses `withAtomic()` method
- Branch strategy: `craft-4` branch for Craft 4 implementation; `main` branch tracks latest (Craft 5)

---

## 2026-02-28 - US-001
- What was implemented: Basic Craft CMS plugin scaffold
- Files changed: `composer.json`, `src/Plugin.php`
- **Learnings for future iterations:**
  - Referenced `craft-imageboss` package for Craft plugin structure patterns
  - `composer.json` needs `type: craft-plugin`, `extra.handle: remote-sync`, `extra.class: jorge\\craftremotesync\\Plugin`
  - Must allow plugins: `yiisoft/yii2-composer` and `craftcms/plugin-installer` in composer config
  - Craft 4 targets: `craftcms/cms ^4.0`, `php ^8.0.2`
  - Plugin class is minimal at scaffold stage - just `init()` calling parent and setting static reference
---

## 2026-02-28 - US-002
- What was implemented: Configuration system with default config file and Plugin `getConfig()` method
- Files changed: `src/config.php` (new), `src/Plugin.php` (updated)
- **Learnings for future iterations:**
  - Default config lives in `src/config.php`; users publish it to `config/remote-sync.php` in their Craft project
  - `Plugin::getConfig()` uses `array_replace_recursive` to merge user config over defaults loaded from `src/config.php`
  - `\Craft::$app->getConfig()->getConfigFromFile('remote-sync')` returns empty array if user hasn't published the config file
  - Environment variable support uses plain `getenv()` in the config file (Craft doesn't provide a global `env()` helper like Laravel)
  - `.chief/` directory is gitignored, so must `git add -f` to commit PRD changes
---

## 2026-02-28 - US-003 & US-004
- What was implemented: RemoteConfig data class and RemoteSyncService with full SSH/rsync operations
- Files changed: `src/models/RemoteConfig.php` (new), `src/services/RemoteSyncService.php` (new), `src/Plugin.php` (updated)
- **Learnings for future iterations:**
  - `readonly` properties require PHP 8.1+; since we target PHP 8.0.2, use regular properties + clone pattern in `withAtomic()` instead
  - Service extends `yii\base\Component` and is registered via `$this->setComponents([...])` in `Plugin::init()`; add a typed getter `getRemoteSyncService(): RemoteSyncService` for IDE support
  - SSH host parsing: split `user@host:port` format with regex; SSH uses `-p PORT`, rsync uses `-e 'ssh -p PORT'`
  - Backup filename parsing: Craft 4 outputs the backup path in stdout; match `storage/backups/([^\s\'"]+)` to extract filename
  - Atomic deployment detection: SSH runs `[ -L /path/current ] && echo yes || echo no`; stored as `isAtomic` on RemoteConfig
  - Craft path for local restore: `\Craft::getAlias('@root') . '/craft'` with `PHP_BINARY` constant
  - Non-capturing catch `catch (\RuntimeException)` (no variable) works in PHP 8.0+
  - US-004 was implemented alongside US-003 since it's a direct prerequisite (RemoteConfig is the return type of service methods)
---

## 2026-02-28 - US-005
- What was implemented: `InteractsWithRemote` PHP trait for Craft console commands with remote selection, guards, confirmation prompts, and preview output
- Files changed: `src/console/traits/InteractsWithRemote.php` (new)
- **Learnings for future iterations:**
  - Trait placed in `src/console/traits/` — namespace `jorge\craftremotesync\console\traits`
  - Trait uses `$this->stdout()`, `$this->stderr()`, `$this->prompt()` — provided by `yii\console\Controller` which Craft console commands extend
  - Guard methods (`ensureNotProduction`, `ensurePushAllowed`) call `exit(1)` after `$this->stderr()` since they are `void` and must abort execution
  - `selectedRemote` protected property stores the current remote so `displayDatabasePreview()` doesn't need parameters
  - `selectRemote()` supports both name-based and numeric index selection when multiple remotes are configured
  - `str_starts_with()` is PHP 8.0+ compatible — safe to use in this project
  - `confirmPull()`/`confirmPush()` use `$this->prompt()` and check for literal string `"yes"` (not just a boolean confirm)
  - `displayFilesPreview()` filters rsync output headers (`sending `, `receiving `, `sent `, `total size `) to count actual file lines
---

## 2026-02-28 - US-006
- What was implemented: `craft remote-sync/pull` console command for pulling database and/or storage files from a remote environment
- Files changed: `src/console/controllers/PullController.php` (new), `src/services/RemoteSyncService.php` (added `createLocalBackup()`), `src/Plugin.php` (registered console controllers namespace)
- **Learnings for future iterations:**
  - Craft console commands: register `$this->controllerNamespace` inside `if (Craft::$app->request->isConsoleRequest)` in `Plugin::init()`; command ID is `plugin-handle/controller-id` (e.g., `remote-sync/pull`)
  - `PullController` extends `craft\console\Controller` and uses `InteractsWithRemote` trait; `$defaultAction = 'index'` makes `craft remote-sync/pull` invoke `actionIndex()`
  - Safety-net backup (local) is created after confirmation but before any remote operations — shows progress with stdout messages throughout
  - Error handling: if remote backup creation/download/restore fails, attempt cleanup of the remote backup before returning exit code 1
  - For files pull: do dry-run previews for all paths first, then single confirmation, then rsync each path in sequence
  - `match` expression is PHP 8.0+ compatible — safe to use in this project
---

## 2026-02-28 - US-007
- What was implemented: `craft remote-sync/push` console command for pushing local database and/or storage files to a remote environment
- Files changed: `src/console/controllers/PushController.php` (new)
- **Learnings for future iterations:**
  - Push command mirrors pull command structure — same operation selection, same InteractsWithRemote trait usage
  - Key difference: `ensurePushAllowed()` guard called before `initializeRemote()` so we fail fast before SSH checks
  - Remote safety backup is created first (before uploading local backup), so remote db is protected even if upload/restore fails
  - Push flow: remote safety backup → create local backup → upload to remote → restore on remote → cleanup both
  - Unlike pull (which cleans up on error), push doesn't need to clean up on error since the remote still has its original safety backup
  - `confirmPush()` shows extra "cannot be undone" warning; files push also uses `confirmPush()` for consistent extra-warning UX
  - `rsyncDryRun(..., 'upload')` used for preview; `rsyncUpload()` used for actual sync
---

## 2026-02-28 - US-008
- What was implemented: Created `craft-4` branch preserving the complete Craft 4 implementation; updated `main` branch for Craft 5 compatibility
- Files changed: `composer.json` (version bumps), `src/models/RemoteConfig.php` (readonly properties)
- **Learnings for future iterations:**
  - Branch strategy: create the preservation branch first from current HEAD, then switch to main and make the version bumps
  - `.gitignore` added on `craft-4` branch to prevent `.chief/` from being tracked
  - Craft 5 requires `craftcms/cms ^5.0` and `php ^8.2`; API surface used by this plugin (console commands, base Plugin class, path helpers) is unchanged between Craft 4 and 5
  - With PHP 8.2+, RemoteConfig uses `readonly` constructor properties; `withAtomic()` updated to `new self(named: args)` instead of clone+mutate pattern
  - `.chief/prds/main/prd.json` is tracked by git even when `.chief/` is in `.gitignore` (was committed before gitignore was added); use `git add -f` if needed
---
