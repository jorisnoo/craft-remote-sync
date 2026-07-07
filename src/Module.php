<?php

namespace Noo\CraftRemoteSync;

use yii\base\Module as BaseModule;

class Module extends BaseModule
{
    public static Module $instance;

    public function init(): void
    {
        \Craft::setAlias('@Noo/CraftRemoteSync', __DIR__);

        parent::init();
        self::$instance = $this;

        $this->registerTranslations();

        if (\Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'Noo\\CraftRemoteSync\\console\\controllers';
        }
    }

    /**
     * Register the `remote-sync` translation category. A yii\base\Module does
     * not get Craft's automatic plugin translation registration, so we do it here.
     */
    private function registerTranslations(): void
    {
        $i18n = \Craft::$app->getI18n();

        if (!isset($i18n->translations['remote-sync'])) {
            $i18n->translations['remote-sync'] = [
                'class' => \craft\i18n\PhpMessageSource::class,
                'sourceLanguage' => 'en',
                'basePath' => __DIR__ . '/translations',
                'forceTranslation' => true,
                'allowOverrides' => true,
            ];
        }
    }

    private ?array $cachedConfig = null;

    public function getConfig(): array
    {
        if ($this->cachedConfig === null) {
            $fileConfig = \Craft::$app->getConfig()->getConfigFromFile('remote-sync');
            $defaults = require __DIR__ . '/config.php';
            $this->cachedConfig = array_replace_recursive($defaults, $fileConfig);
        }

        return $this->cachedConfig;
    }
}
