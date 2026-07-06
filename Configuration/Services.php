<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Projection\Mcp\AbilityMcpTool;

/**
 * Projects the abilities registry onto the MCP surface: for every service
 * tagged "abilities.ability" whose #[AsAbility] exposes "mcp", one
 * AbilityMcpTool definition is generated and tagged "mcp.tool" so
 * hn/typo3-mcp-server's ToolRegistry collects it like a native tool.
 *
 * One registry, many protocol projections — the MCP tool list is generated
 * from the registry at container compile time, never hand-rolled.
 *
 * Runs after ResolveInstanceofConditionalsPass (priority 100 > 0), so the
 * tags materialized from #[AutoconfigureTag('abilities.ability')] are
 * visible to findTaggedServiceIds().
 */
return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new class () implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            if (!interface_exists(\Hn\McpServer\MCP\Tool\ToolInterface::class)) {
                return;
            }

            foreach (array_keys($container->findTaggedServiceIds('abilities.ability')) as $serviceId) {
                $class = $container->getDefinition($serviceId)->getClass() ?? $serviceId;
                if (!class_exists($class)) {
                    continue;
                }

                $attributes = (new \ReflectionClass($class))->getAttributes(AsAbility::class);
                if ($attributes === []) {
                    continue;
                }
                if (!in_array('mcp', $attributes[0]->newInstance()->expose, true)) {
                    continue;
                }

                $definition = new Definition(AbilityMcpTool::class, [
                    new Reference($serviceId),
                    new Reference(AbilityExecutor::class),
                ]);
                $definition->setPublic(true);
                $definition->addTag('mcp.tool');
                $container->setDefinition('abilities.mcp_tool.' . $serviceId, $definition);
            }
        }
    });
};
