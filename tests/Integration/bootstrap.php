<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Shopware\Core\TestBootstrapper;

$pluginRoot = dirname(__DIR__, 2);
$projectRoot = getenv('SHOPWARE_PROJECT_ROOT') ?: $pluginRoot;
if (!is_dir($projectRoot . '/vendor')) {
    throw new RuntimeException('SHOPWARE_PROJECT_ROOT must point to an installed Shopware project.');
}

/** @var ClassLoader $classLoader */
$classLoader = require $projectRoot . '/vendor/autoload.php';
$classLoader->addPsr4('Skyyware\\SkyyMailTemplateSync\\', $pluginRoot . '/src', true);
$classLoader->addPsr4('Skyyware\\SkyyMailTemplateSync\\Tests\\', $pluginRoot . '/tests', true);

(new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->setClassLoader($classLoader)
    ->setPlatformEmbedded(false)
    ->addCallingPlugin($pluginRoot . '/composer.json')
    ->setForceInstallPlugins(true)
    ->bootstrap();
