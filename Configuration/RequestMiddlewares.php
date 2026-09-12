<?php

declare(strict_types=1);

use Webconsulting\Abilities\Http\RestMiddleware;

/**
 * The REST projection answers below /abilities/v1 (configurable) before
 * site resolution — the API needs no site, page or TypoScript.
 */
return [
    'frontend' => [
        'webconsulting/abilities/rest' => [
            'target' => RestMiddleware::class,
            'description' => 'REST projection of the abilities registry (/abilities/v1)',
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
