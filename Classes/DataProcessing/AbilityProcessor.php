<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\DataProcessing;

use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The Fluid surface: a data processor that runs a read-only ability while a
 * page renders and hands the result envelope to the template.
 *
 *   dataProcessing {
 *     10 = Webconsulting\Abilities\DataProcessing\AbilityProcessor
 *     10 {
 *       ability = content/search
 *       input {
 *         term.field = title
 *         limit = 5
 *       }
 *       as = search
 *     }
 *   }
 *
 * Every input value goes through stdWrap and is coerced to the type the
 * ability's input schema declares. Only read-only abilities may render: a
 * page view must never write. The context is trusted (the integrator wrote
 * the TypoScript) — the site policy and the ability's own permission check
 * still apply, and every run is traced with surface "frontend".
 */
final class AbilityProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
        private readonly SchemaValidator $validator,
    ) {}

    /**
     * @param array<string, mixed> $contentObjectConfiguration
     * @param array<string, mixed> $processorConfiguration
     * @param array<string, mixed> $processedData
     * @return array<string, mixed>
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData,
    ): array {
        $name = (string)$cObj->stdWrapValue('ability', $processorConfiguration);
        if (!$this->registry->has($name)) {
            throw new \InvalidArgumentException(
                sprintf('AbilityProcessor: unknown ability "%s"; set "ability" to a registered name (see abilities:list).', $name),
                7480291070,
            );
        }
        $definition = $this->registry->getDefinition($name);
        if (!$definition->isReadOnly()) {
            throw new \InvalidArgumentException(
                sprintf('AbilityProcessor: ability "%s" is not read-only; a page render must not run abilities with side effects.', $name),
                7480291071,
            );
        }

        $inputConfiguration = is_array($processorConfiguration['input.'] ?? null) ? $processorConfiguration['input.'] : [];
        $input = [];
        foreach (array_keys($inputConfiguration) as $key) {
            $property = rtrim((string)$key, '.');
            $input[$property] = $cObj->stdWrapValue($property, $inputConfiguration);
        }

        $ability = $this->registry->get($name);
        $result = $this->executor->execute(
            $ability,
            $this->validator->coerce($input, $ability->getInputSchema()),
            ExecutionContext::frontend(),
            $definition,
        );

        $as = (string)$cObj->stdWrapValue('as', $processorConfiguration, 'ability');
        $processedData[$as] = $result->toArray();

        return $processedData;
    }
}
