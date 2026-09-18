<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\Abilities\Backend\Tca\RegistryItemsProcFunc;
use Webconsulting\Abilities\Reaction\RunAbilityReaction;

defined('TYPO3') or die();

// The webhook surface exists only together with EXT:reactions.
if (!ExtensionManagementUtility::isLoaded('reactions')) {
    return;
}

$ll = 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:';

ExtensionManagementUtility::addTCAcolumns('sys_reaction', [
    RunAbilityReaction::FIELD_ABILITY => [
        'label' => $ll . 'sys_reaction.tx_abilities_ability',
        'description' => $ll . 'sys_reaction.tx_abilities_ability.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'required' => true,
            'items' => [
                ['label' => $ll . 'sys_reaction.tx_abilities_ability.select', 'value' => ''],
            ],
            'itemsProcFunc' => RegistryItemsProcFunc::class . '->addAbilities',
        ],
    ],
]);

ExtensionManagementUtility::addTcaSelectItem('sys_reaction', 'reaction_type', [
    'label' => RunAbilityReaction::getDescription(),
    'value' => RunAbilityReaction::getType(),
    'icon' => RunAbilityReaction::getIconIdentifier(),
]);

$GLOBALS['TCA']['sys_reaction']['ctrl']['typeicon_classes'][RunAbilityReaction::getType()] = RunAbilityReaction::getIconIdentifier();

$GLOBALS['TCA']['sys_reaction']['palettes']['abilitiesRun'] = [
    'label' => 'reactions.db:palette.additional',
    'showitem' => RunAbilityReaction::FIELD_ABILITY . ', --linebreak--, impersonate_user',
];

$GLOBALS['TCA']['sys_reaction']['types'][RunAbilityReaction::getType()] = [
    'showitem' => '
        --div--;core.form.tabs:general,
        --palette--;;config,
        --palette--;;abilitiesRun,
        --div--;core.form.tabs:access,
        --palette--;;access',
    'columnsOverrides' => [
        'impersonate_user' => [
            'config' => [
                'minitems' => 1,
            ],
        ],
    ],
];
