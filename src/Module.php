<?php

namespace Noo\CraftRemoteSync;

use craft\base\Module as BaseModule;
use Noo\CraftRemoteSync\services\RemoteSyncService;

class Module extends BaseModule
{
    public static Module $instance;

    public function init(): void
    {
        parent::init();
        self::$instance = $this;

        $this->setComponents([
            'remoteSyncService' => RemoteSyncService::class,
        ]);

        if (\Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'Noo\\CraftRemoteSync\\console\\controllers';
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
