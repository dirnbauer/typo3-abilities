<?php

declare(strict_types=1);

/*
 * TYPO3 coding guidelines (typo3/coding-standards) for the whole extension.
 * CI runs "composer cgl" (php-cs-fixer fix --dry-run --diff) against this configuration.
 */
$config = \TYPO3\CodingStandards\CsFixerConfig::create();
$config->getFinder()
    ->in(__DIR__)
    ->exclude([
        '.Build',
        'Documentation-GENERATED-temp',
    ]);

return $config;
