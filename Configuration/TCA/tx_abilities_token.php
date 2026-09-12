<?php

declare(strict_types=1);

$ll = 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $ll . 'tx_abilities_token',
        'label' => 'name',
        'label_alt' => 'be_user',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'delete' => 'deleted',
        'default_sortby' => 'uid DESC',
        'rootLevel' => 1,
        'iconfile' => 'EXT:abilities/Resources/Public/Icons/module.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'name' => [
            'label' => $ll . 'tx_abilities_token.name',
            'config' => ['type' => 'input', 'required' => true, 'max' => 255],
        ],
        'be_user' => [
            'label' => $ll . 'tx_abilities_token.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'foreign_table_where' => 'ORDER BY be_users.username',
                'readOnly' => true,
            ],
        ],
        'scopes' => [
            'label' => $ll . 'tx_abilities_token.scopes',
            'config' => ['type' => 'text', 'readOnly' => true, 'rows' => 3],
        ],
        'expires' => [
            'label' => $ll . 'tx_abilities_token.expires',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'last_used' => [
            'label' => $ll . 'tx_abilities_token.last_used',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'token_hash' => [
            'label' => $ll . 'tx_abilities_token.token_hash',
            'config' => ['type' => 'input', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'name, be_user, scopes, expires, last_used, token_hash',
        ],
    ],
];
