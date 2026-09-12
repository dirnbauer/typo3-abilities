<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Category;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webconsulting\Abilities\Domain\AbilityCategory;

/**
 * Provides ability categories at runtime (computed, translated, or read
 * from configuration). Implementations are collected into the
 * CategoryRegistry via the "abilities.category_provider" DI tag.
 * Static categories can use the #[AsAbilityCategory] attribute instead.
 */
#[AutoconfigureTag('abilities.category_provider')]
interface AbilityCategoryProviderInterface
{
    /**
     * @return iterable<AbilityCategory>
     */
    public function getAbilityCategories(): iterable;
}
