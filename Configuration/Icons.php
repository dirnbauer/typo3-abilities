<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'abilities-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:abilities/Resources/Public/Icons/module.svg',
    ],
    'abilities-token' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:abilities/Resources/Public/Icons/record-token.svg',
    ],
    'abilities-trace' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:abilities/Resources/Public/Icons/record-trace.svg',
    ],
];
