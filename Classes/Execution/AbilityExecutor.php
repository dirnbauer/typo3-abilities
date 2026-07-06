<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Execution;

use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilityInterface;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The one execution pipeline every projection (MCP, CLI, REST, PHP) goes
 * through:
 *
 *   1. policy gate        (site-wide abilities policy: deny/review/risk cap)
 *   2. input validation   (against the ability's input schema, with defaults)
 *   3. scope check        (explicitly granted scopes, if the context has any)
 *   4. permission check   (the ability's own checkPermission())
 *   5. execute
 *   6. output validation  (against the ability's output schema)
 *
 * Mirrors the WordPress Abilities API execution order, with the policy gate
 * in front because governance outranks contracts.
 */
class AbilityExecutor
{
    public function __construct(
        private readonly SchemaValidator $validator,
        private readonly PolicyProvider $policyProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function execute(AbilityInterface $ability, array $input, ExecutionContext $context): AbilityResult
    {
        $definition = AbilityDefinition::fromInstance($ability);

        $decision = $this->policyProvider->get()->decide($definition, $context);
        if (!$decision->allowed) {
            return AbilityResult::failure(AbilityResult::ERROR_POLICY_DENIED, $decision->reason ?? 'Denied by policy.');
        }

        $inputSchema = $ability->getInputSchema();
        $input = $this->validator->applyDefaults($input, $inputSchema);
        $inputErrors = $this->validator->validate($input, $inputSchema, '$.input');
        if ($inputErrors !== []) {
            return AbilityResult::failure(AbilityResult::ERROR_INVALID_INPUT, implode('; ', $inputErrors));
        }

        $missingScopes = $context->missingScopes($definition->scopes);
        if ($missingScopes !== []) {
            return AbilityResult::failure(
                AbilityResult::ERROR_PERMISSION_DENIED,
                sprintf(
                    'Ability "%s" requires scopes not granted to this context: %s.',
                    $definition->name,
                    implode(', ', $missingScopes),
                ),
            );
        }

        $permission = $ability->checkPermission($input, $context);
        if ($permission !== true) {
            return AbilityResult::failure(
                AbilityResult::ERROR_PERMISSION_DENIED,
                is_string($permission)
                    ? $permission
                    : sprintf('Permission check of ability "%s" denied execution.', $definition->name),
            );
        }

        try {
            $output = $ability->execute($input, $context);
        } catch (\Throwable $exception) {
            return AbilityResult::failure(
                AbilityResult::ERROR_EXECUTION_ERROR,
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
            );
        }

        $outputErrors = $this->validator->validate($output, $ability->getOutputSchema(), '$.output');
        if ($outputErrors !== []) {
            return AbilityResult::failure(
                AbilityResult::ERROR_INVALID_OUTPUT,
                sprintf(
                    'Ability "%s" executed (side effects may have happened) but violated its output contract: %s',
                    $definition->name,
                    implode('; ', $outputErrors),
                ),
            );
        }

        return AbilityResult::success($output);
    }
}
