<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the unit suite — the testing-framework boilerplate
 * (TYPO3 constants, class loader, default TYPO3_CONF_VARS, package manager)
 * so tests may use TYPO3 core classes without a database.
 */
(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();

    if (!getenv('TYPO3_PATH_ROOT')) {
        putenv('TYPO3_PATH_ROOT=' . rtrim($testbase->getWebRoot(), '/'));
    }
    if (!getenv('TYPO3_PATH_WEB')) {
        putenv('TYPO3_PATH_WEB=' . rtrim($testbase->getWebRoot(), '/'));
    }

    $testbase->defineSitePath();

    $composerMode = defined('TYPO3_COMPOSER_MODE') && TYPO3_COMPOSER_MODE === true;
    \TYPO3\TestingFramework\Core\SystemEnvironmentBuilder::run(
        0,
        \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI,
        $composerMode,
    );

    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3conf/ext');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/assets');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/var/tests');
    $testbase->createDirectory(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/typo3temp/var/transient');

    $classLoader = require $testbase->getPackagesPath() . '/autoload.php';
    \TYPO3\CMS\Core\Core\Bootstrap::initializeClassLoader($classLoader);

    $configurationManager = new \TYPO3\CMS\Core\Configuration\ConfigurationManager();
    $GLOBALS['TYPO3_CONF_VARS'] = $configurationManager->getDefaultConfiguration();

    $cache = new \TYPO3\CMS\Core\Cache\Frontend\PhpFrontend(
        'core',
        new \TYPO3\CMS\Core\Cache\Backend\NullBackend('production', []),
    );
    $packageManager = \TYPO3\CMS\Core\Core\Bootstrap::createPackageManager(
        \TYPO3\CMS\Core\Package\UnitTestPackageManager::class,
        \TYPO3\CMS\Core\Core\Bootstrap::createPackageCache($cache),
    );

    \TYPO3\CMS\Core\Utility\GeneralUtility::setSingletonInstance(\TYPO3\CMS\Core\Package\PackageManager::class, $packageManager);
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::setPackageManager($packageManager);

    $testbase->dumpClassLoadingInformation();

    \TYPO3\CMS\Core\Utility\GeneralUtility::purgeInstances();
})();
