<?php

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

if (! InstalledVersions::satisfies(new VersionParser(), 'craftcms/cms', '^5.0')) {
    throw new RuntimeException('The main branch tests require Craft 5. Refresh dependencies with composer update.');
}

defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require_once dirname(__DIR__).'/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__).'/vendor/craftcms/cms/src/Craft.php';
