<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Shopware\Core\TestBootstrapper;

$pluginRoot = dirname(__DIR__, 2);
$runtimePluginRoot = getenv('SKYY_PLUGIN_RUNTIME_ROOT') ?: $pluginRoot;
$projectRoot = getenv('SHOPWARE_PROJECT_ROOT') ?: $pluginRoot;
if (!is_dir($projectRoot . '/vendor')) {
    throw new RuntimeException('SHOPWARE_PROJECT_ROOT must point to an installed Shopware project.');
}
if (!is_file($runtimePluginRoot . '/src/SkyyMailTemplateSync.php')) {
    throw new RuntimeException('SKYY_PLUGIN_RUNTIME_ROOT must point to a packaged plugin runtime.');
}

/** @var ClassLoader $classLoader */
$classLoader = require $projectRoot . '/vendor/autoload.php';
$classLoader->addPsr4('Skyyware\\SkyyMailTemplateSync\\', $runtimePluginRoot . '/src', true);
$classLoader->addPsr4('Skyyware\\SkyyMailTemplateSync\\Tests\\', $pluginRoot . '/tests', true);

(new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->setClassLoader($classLoader)
    ->setPlatformEmbedded(false)
    ->addCallingPlugin($runtimePluginRoot . '/composer.json')
    ->setForceInstallPlugins(true)
    ->bootstrap();
