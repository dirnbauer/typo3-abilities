<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the functional suite (testing-framework boilerplate):
 * defines ORIGINAL_ROOT and creates the test instance directories.
 */
(static function (): void {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
