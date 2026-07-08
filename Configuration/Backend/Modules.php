<?php

declare(strict_types=1);

use Webconsulting\Abilities\Backend\Controller\AbilitiesModuleController;

/**
 * The abilities registry as a native backend module — the backend
 * projection. It runs inside the authenticated TYPO3 backend, so it needs
 * no separate login or API token: the logged-in backend user IS the
 * identity, and abilities execute through the same governed pipeline as
 * every other surface.
 */
return [
    'system_abilities' => [
        'parent' => 'system',
        'position' => ['bottom'],
        'access' => 'admin',
        'workspaces' => '*',
        'path' => '/module/system/abilities',
        'iconIdentifier' => 'abilities-module',
        'labels' => 'LLL:EXT:abilities/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => AbilitiesModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
