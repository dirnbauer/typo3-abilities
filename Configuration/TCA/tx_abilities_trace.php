<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Ability execution trace',
        'label' => 'ability',
        'label_alt' => 'surface,error_code',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'rootLevel' => 1,
        'readOnly' => true,
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/actions/actions-list.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'ability' => [
            'label' => 'Ability',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'surface' => [
            'label' => 'Surface',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'ok' => [
            'label' => 'Success',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'error_code' => [
            'label' => 'Error code',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'error' => [
            'label' => 'Error',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'input' => [
            'label' => 'Input (JSON)',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'duration_ms' => [
            'label' => 'Duration (ms)',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'be_user' => [
            'label' => 'Backend user',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'ability, surface, ok, error_code, error, input, duration_ms, be_user',
        ],
    ],
];
