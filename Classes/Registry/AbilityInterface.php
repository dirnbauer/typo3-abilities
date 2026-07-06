<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Registry;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * An ability: one typed, permissioned unit of functionality.
 *
 * Implementations MUST also carry the #[AsAbility] attribute — the
 * attribute holds the registry metadata (name, scopes, risk tier, side
 * effects), the interface holds the contract and behavior. Implementing
 * classes are collected into the AbilitiesRegistry automatically via the
 * "abilities.ability" DI tag.
 */
#[AutoconfigureTag('abilities.ability')]
interface AbilityInterface
{
    /**
     * JSON Schema for the ability input. Return [] to accept any input.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * JSON Schema for the ability output. Return [] to skip output validation.
     *
     * @return array<string, mixed>
     */
    public function getOutputSchema(): array;

    /**
     * Ability-specific permission check, evaluated after policy and scope
     * checks. Return true to allow, false to deny, or a string with the
     * denial reason.
     *
     * @param array<string, mixed> $input
     */
    public function checkPermission(array $input, ExecutionContext $context): bool|string;

    /**
     * Execute the ability. The returned value is validated against the
     * output schema.
     *
     * @param array<string, mixed> $input
     */
    public function execute(array $input, ExecutionContext $context): mixed;
}
