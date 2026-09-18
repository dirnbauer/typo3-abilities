<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;
use Webconsulting\Abilities\Category\AsAbilityCategory;
use Webconsulting\Abilities\Reaction\RunAbilityReaction;

/**
 * Container wiring that Services.yaml cannot express:
 *  - any DI-managed class carrying #[AsAbilityCategory] is tagged
 *    "abilities.category_provider" so the CategoryRegistry collects its
 *    categories — no interface required for static category declarations;
 *  - the webhook reaction is registered only when EXT:reactions is installed,
 *    because its interface does not exist otherwise.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->registerAttributeForAutoconfiguration(
        AsAbilityCategory::class,
        static function (ChildDefinition $definition, AsAbilityCategory $attribute, \Reflector $reflector): void {
            $definition->addTag('abilities.category_provider');
        },
    );

    if (interface_exists(ReactionInterface::class)) {
        $configurator->services()
            ->set(RunAbilityReaction::class)
            ->autowire()
            ->autoconfigure();
    }
};
