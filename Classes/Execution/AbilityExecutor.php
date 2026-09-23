<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Execution;

use Psr\EventDispatcher\EventDispatcherInterface;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Event\AfterAbilityExecutionEvent;
use Webconsulting\Abilities\Event\BeforeAbilityExecutionEvent;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilityInterface;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The one execution pipeline every surface goes through:
 *
 *   0. BeforeAbilityExecutionEvent (listeners may rewrite input or veto)
 *   1. policy gate        (site-wide abilities policy: deny/review/risk cap)
 *   2. input validation   (against the ability's input schema, with defaults)
 *   3. scope check        (explicitly granted scopes, if the context has any)
 *   4. permission check   (the ability's own checkPermission())
 *   5. execute
 *   6. output validation  (against the ability's output schema)
 *   7. AfterAbilityExecutionEvent (always — denials and failures included)
 *
 * Mirrors the WordPress Abilities API execution order, with the policy gate
 * in front because governance outranks contracts.
 */
final readonly class AbilityExecutor
{
    public function __construct(
        private SchemaValidator $validator,
        private PolicyProvider $policyProvider,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @param AbilityDefinition|null $definition the registry's (possibly modified) definition; derived from the class when omitted
     */
    public function execute(
        AbilityInterface $ability,
        array $input,
        ExecutionContext $context,
        ?AbilityDefinition $definition = null,
    ): AbilityResult {
        $definition ??= AbilityDefinition::fromInstance($ability);
        $started = hrtime(true);

        $before = new BeforeAbilityExecutionEvent($definition, $context, $input);
        $this->eventDispatcher?->dispatch($before);

        $result = $before->isDenied()
            ? AbilityResult::failure(
                AbilityErrorCode::PolicyDenied,
                $before->getDenialReason() ?? 'Denied by a BeforeAbilityExecutionEvent listener.',
            )
            : $this->runPipeline($ability, $definition, $before->getInput(), $context);

        $this->eventDispatcher?->dispatch(new AfterAbilityExecutionEvent(
            definition: $definition,
            context: $context,
            input: $input,
            result: $result,
            durationMs: (hrtime(true) - $started) / 1_000_000,
        ));

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runPipeline(
        AbilityInterface $ability,
        AbilityDefinition $definition,
        array $input,
        ExecutionContext $context,
    ): AbilityResult {
        $decision = $this->policyProvider->get()->decide($definition, $context);
        if (!$decision->allowed) {
            return AbilityResult::failure(
                $decision->reviewRequired ? AbilityErrorCode::ReviewRequired : AbilityErrorCode::PolicyDenied,
                $decision->reason ?? 'Denied by policy.',
            );
        }

        $inputSchema = $ability->getInputSchema();
        $input = $this->validator->applyDefaults($input, $inputSchema);
        $inputErrors = $this->validator->validate($input, $inputSchema, '$.input');
        if ($inputErrors !== []) {
            return AbilityResult::failure(AbilityErrorCode::InvalidInput, implode('; ', $inputErrors));
        }

        $missingScopes = $context->missingScopes($definition->scopes);
        if ($missingScopes !== []) {
            return AbilityResult::failure(
                AbilityErrorCode::InvalidPermissions,
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
                AbilityErrorCode::InvalidPermissions,
                is_string($permission)
                    ? $permission
                    : sprintf('Permission check of ability "%s" denied execution.', $definition->name),
            );
        }

        try {
            $output = $ability->execute($input, $context);
        } catch (\Throwable $exception) {
            return AbilityResult::failure(
                AbilityErrorCode::CannotExecute,
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
            );
        }

        $outputErrors = $this->validator->validate($output, $ability->getOutputSchema(), '$.output');
        if ($outputErrors !== []) {
            return AbilityResult::failure(
                AbilityErrorCode::InvalidOutput,
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
