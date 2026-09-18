<?php

declare(strict_types=1);

$ll = 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_abilities_trace',
        'label' => 'ability',
        'label_alt' => 'surface,error_code',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'rootLevel' => 1,
        'readOnly' => true,
        'iconfile' => 'EXT:abilities/Resources/Public/Icons/record-trace.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'ability' => [
            'label' => $ll . 'tx_abilities_trace.ability',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'surface' => [
            'label' => $ll . 'tx_abilities_trace.surface',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'ok' => [
            'label' => $ll . 'tx_abilities_trace.ok',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
        'error_code' => [
            'label' => $ll . 'tx_abilities_trace.error_code',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
        'error' => [
            'label' => $ll . 'tx_abilities_trace.error',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'input' => [
            'label' => $ll . 'tx_abilities_trace.input',
            'config' => ['type' => 'text', 'readOnly' => true],
        ],
        'duration_ms' => [
            'label' => $ll . 'tx_abilities_trace.duration_ms',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'be_user' => [
            'label' => $ll . 'tx_abilities_trace.be_user',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'ability, surface, ok, error_code, error, input, duration_ms, be_user',
        ],
    ],
];
