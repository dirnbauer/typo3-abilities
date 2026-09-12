<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\Abilities\Permission\ScopeItemsProcFunc;

defined('TYPO3') or die();

ExtensionManagementUtility::addTCAcolumns('be_groups', [
    'tx_abilities_scopes' => [
        'label' => 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:be_groups.tx_abilities_scopes',
        'description' => 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:be_groups.tx_abilities_scopes.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectMultipleSideBySide',
            'items' => [
                [
                    'label' => 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:be_groups.tx_abilities_scopes.all',
                    'value' => '*',
                ],
            ],
            'itemsProcFunc' => ScopeItemsProcFunc::class . '->addAbilityScopes',
            'size' => 8,
            'autoSizeMax' => 20,
            'maxitems' => 999,
            'default' => '',
        ],
    ],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'be_groups',
    '--div--;LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:be_groups.tab, tx_abilities_scopes',
);
