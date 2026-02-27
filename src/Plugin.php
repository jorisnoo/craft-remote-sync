<?php

namespace jorge\craftremotesync;

use craft\base\Plugin as BasePlugin;

class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
    }

    public function getConfig(): array
    {
        $fileConfig = \Craft::$app->getConfig()->getConfigFromFile('remote-sync');
        $defaults = require __DIR__ . '/config.php';

        return array_replace_recursive($defaults, $fileConfig);
    }
}
