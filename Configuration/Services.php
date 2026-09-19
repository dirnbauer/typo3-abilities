<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
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
 *    because its interface does not exist otherwise;
 *  - services still carrying the 1.1 tag "abilities.capability_source" are
 *    promoted to "abilities.catalog_source"
 *    (@deprecated since 1.2.0, removed in 2.0.0).
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->registerAttributeForAutoconfiguration(
        AsAbilityCategory::class,
        static function (ChildDefinition $definition, AsAbilityCategory $attribute, \Reflector $reflector): void {
            $definition->addTag('abilities.category_provider');
        },
    );

    $containerBuilder->addCompilerPass(new class implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            foreach (array_keys($container->findTaggedServiceIds('abilities.capability_source')) as $id) {
                $definition = $container->findDefinition($id);
                if (!$definition->hasTag('abilities.catalog_source')) {
                    $definition->addTag('abilities.catalog_source');
                }
            }
        }
    });

    if (interface_exists(ReactionInterface::class)) {
        $configurator->services()
            ->set(RunAbilityReaction::class)
            ->autowire()
            ->autoconfigure();
    }
};
