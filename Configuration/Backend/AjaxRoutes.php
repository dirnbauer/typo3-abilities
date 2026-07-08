<?php

declare(strict_types=1);

use Webconsulting\Abilities\Backend\Controller\AbilitiesAjaxController;

/**
 * Backend AJAX routes for the abilities module. Both are guarded by the
 * backend user session (same-origin, CSRF-checked by @typo3/core/ajax) —
 * there is no separate token or login.
 */
return [
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
];
