<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Category;

/**
 * Declares an ability category on any DI-managed class (repeatable). The
 * class is tagged "abilities.category_provider" automatically (see
 * Configuration/Services.php) and the CategoryRegistry reads the attribute
 * off it — no interface to implement for static categories:
 *
 *   #[AsAbilityCategory(slug: 'news', label: 'News', description: 'Editorial news operations')]
 *   final class NewsAbilityCategories {}
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class AsAbilityCategory
{
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $description = '',
    ) {}
}
