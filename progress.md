## Codebase Patterns
- Craft 4 plugin: `composer.json` uses `type: craft-plugin`, `extra.handle`, `extra.class`, `extra.name`
- Namespace: `jorge\craftremotesync\` (lowercase jorge, no separators) pointing to `src/`
- Plugin class extends `craft\base\Plugin` (not just `Plugin`)
- Craft 4 plugin installer plugin: `craftcms/plugin-installer` must be allowed in `config.allow-plugins`
- Yii plugin: `yiisoft/yii2-composer` must also be allowed (Craft 4 uses Yii2)
- Console commands in Craft use `craft\console\Controller` (not `craft\base\Controller`)
- Quality check: `php -l` for syntax, `php -r "json_decode(...)"` for JSON validity
- Porting from laravel-remote-sync: Craft uses `craft db/backup --zip` and `craft db/restore <path> --drop-all-tables` instead of artisan snapshot commands
- Config in Craft: use `\Craft::$app->getConfig()->getConfigFromFile('remote-sync')` to load from `config/remote-sync.php`
- Service registration in Craft: use `$this->setComponents([...])` inside `Plugin::init()`
- Craft environment check: use `\Craft::$app->env` (Craft 4) or check `CRAFT_ENVIRONMENT` env var

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
