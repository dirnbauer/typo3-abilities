<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Webconsulting\Abilities\Category\AsAbilityCategory;

/**
 * Container wiring that Services.yaml cannot express: any DI-managed class
 * carrying #[AsAbilityCategory] is tagged "abilities.category_provider" so
 * the CategoryRegistry collects its categories — no interface required for
 * static category declarations.
 *
 * The MCP projection is no longer wired here: since 1.0.0 the registry
 * exposes protocol-neutral descriptors (Projection\Mcp\McpProjection) and
 * the MCP server extension owns the bridge to its tool interface.
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->registerAttributeForAutoconfiguration(
        AsAbilityCategory::class,
        static function (ChildDefinition $definition, AsAbilityCategory $attribute, \Reflector $reflector): void {
            $definition->addTag('abilities.category_provider');
        },
    );
};
