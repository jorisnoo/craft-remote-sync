<?php

namespace jorge\craftremotesync;

use craft\base\Plugin as BasePlugin;
use jorge\craftremotesync\services\RemoteSyncService;

class Plugin extends BasePlugin
{
    public static Plugin $plugin;

    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'remoteSyncService' => RemoteSyncService::class,
        ]);

        if (\Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'jorge\\craftremotesync\\console\\controllers';
        }
    }

    public function getRemoteSyncService(): RemoteSyncService
    {
        return $this->get('remoteSyncService');
    }

    public function getConfig(): array
    {
        $fileConfig = \Craft::$app->getConfig()->getConfigFromFile('remote-sync');
        $defaults = require __DIR__ . '/config.php';

        return array_replace_recursive($defaults, $fileConfig);
    }
}
