<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * One ability category: a slug abilities reference via #[AsAbility(category:)]
 * plus its presentation. Categories are collected by the CategoryRegistry.
 */
final readonly class AbilityCategory
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]*$/';

    public function __construct(
        public string $slug,
        public string $label,
        public string $description = '',
    ) {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Ability category slug "%s" is invalid. Expected lowercase kebab-case, e.g. "content".', $slug),
                7480291010,
            );
        }
    }

    /**
     * @return array{slug: string, label: string, description: string}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'description' => $this->description,
        ];
    }
}
