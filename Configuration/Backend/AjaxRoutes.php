<?php

declare(strict_types=1);

use Webconsulting\Abilities\Backend\Controller\AbilitiesAjaxController;

/**
 * Backend AJAX routes for the abilities module and the client.js ES module.
 * All are guarded by the backend user session (same-origin, CSRF-checked
 * by @typo3/core/ajax) and inherit the module's admin-only access — there
 * is no separate token or login.
 */
return [
    'abilities_list' => [
        'path' => '/abilities/list',
        'target' => AbilitiesAjaxController::class . '::list',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_describe' => [
        'path' => '/abilities/describe',
        'target' => AbilitiesAjaxController::class . '::describe',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_run' => [
        'path' => '/abilities/run',
        'target' => AbilitiesAjaxController::class . '::run',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_categories' => [
        'path' => '/abilities/categories',
        'target' => AbilitiesAjaxController::class . '::categories',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_tokens' => [
        'path' => '/abilities/tokens',
        'target' => AbilitiesAjaxController::class . '::tokens',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_token_create' => [
        'path' => '/abilities/tokens/create',
        'target' => AbilitiesAjaxController::class . '::tokenCreate',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_token_revoke' => [
        'path' => '/abilities/tokens/revoke',
        'target' => AbilitiesAjaxController::class . '::tokenRevoke',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
    'abilities_traces' => [
        'path' => '/abilities/traces',
        'target' => AbilitiesAjaxController::class . '::traceList',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'system_abilities',
    ],
];
