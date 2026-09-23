<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Registry\AbilityInterface;

/**
 * Immutable registry entry: the #[AsAbility] metadata of one ability
 * implementation, plus derived projection facts (annotations, MCP tool
 * name, REST run method).
 */
final readonly class AbilityDefinition
{
    private function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string $category,
        /** @var list<string> */
        public array $scopes,
        public RiskTier $riskTier,
        /** @var list<string> */
        public array $sideEffects,
        public bool $idempotent,
        public bool $destructive,
        public bool $readOnly,
        public string $instructions,
        /** @var list<string> */
        public array $expose,
        /** @var array<string, mixed> */
        public array $meta,
        /** @var class-string<AbilityInterface> */
        public string $className,
    ) {}

    public static function fromInstance(AbilityInterface $ability): self
    {
        return self::fromClassName($ability::class);
    }

    /**
     * @param class-string<AbilityInterface> $className
     */
    public static function fromClassName(string $className): self
    {
        $attributes = new \ReflectionClass($className)->getAttributes(AsAbility::class);
        if ($attributes === []) {
            throw new \LogicException(
                sprintf('Ability class %s must carry the #[AsAbility] attribute.', $className),
                7480291002,
            );
        }

        $attribute = $attributes[0]->newInstance();

        return new self(
            name: $attribute->name,
            title: $attribute->title,
            description: $attribute->description,
            category: $attribute->category,
            scopes: array_values($attribute->scopes),
            riskTier: $attribute->riskTier,
            sideEffects: array_values($attribute->sideEffects),
            idempotent: $attribute->idempotent,
            destructive: $attribute->destructive,
            readOnly: $attribute->isReadOnly(),
            instructions: $attribute->instructions,
            expose: array_values($attribute->expose),
            meta: $attribute->meta,
            className: $className,
        );
    }

    /**
     * Derived copy with governance facts overridden — the mutation surface of
     * ModifyAbilityDefinitionEvent.
     *
     * @param list<string>|null $expose
     */
    public function with(?array $expose = null, ?RiskTier $riskTier = null, ?bool $readOnly = null): self
    {
        return new self(
            name: $this->name,
            title: $this->title,
            description: $this->description,
            category: $this->category,
            scopes: $this->scopes,
            riskTier: $riskTier ?? $this->riskTier,
            sideEffects: $this->sideEffects,
            idempotent: $this->idempotent,
            destructive: $this->destructive,
            readOnly: $readOnly ?? $this->readOnly,
            instructions: $this->instructions,
            expose: $expose === null ? $this->expose : array_values($expose),
            meta: $this->meta,
            className: $this->className,
        );
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function isExposedTo(string $surface): bool
    {
        return in_array($surface, $this->expose, true);
    }

    /**
     * The WordPress-compatible annotation block.
     *
     * @return array{readonly: bool, destructive: bool, idempotent: bool, instructions: string}
     */
    public function annotations(): array
    {
        return [
            'readonly' => $this->readOnly,
            'destructive' => $this->destructive,
            'idempotent' => $this->idempotent,
            'instructions' => $this->instructions,
        ];
    }

    /**
     * HTTP method the REST run endpoint accepts, derived from the
     * annotations exactly like the WordPress Abilities REST API:
     * read-only → GET, destructive → DELETE, everything else → POST.
     */
    public function restMethod(): string
    {
        if ($this->readOnly) {
            return 'GET';
        }

        return $this->destructive ? 'DELETE' : 'POST';
    }

    /**
     * MCP tool name projection. Ability names are lowercase kebab-case with
     * exactly one "/", and "_" cannot occur in them — so replacing "/" with
     * "_" is collision-free and reversible.
     */
    public function mcpToolName(): string
    {
        return 'ability_' . str_replace('/', '_', $this->name);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'scopes' => $this->scopes,
            'riskTier' => $this->riskTier->value,
            'sideEffects' => $this->sideEffects,
            'idempotent' => $this->idempotent,
            'destructive' => $this->destructive,
            'readOnly' => $this->readOnly,
            'instructions' => $this->instructions,
            'annotations' => $this->annotations(),
            'expose' => $this->expose,
            'meta' => $this->meta,
            'className' => $this->className,
            'mcpToolName' => $this->mcpToolName(),
            'restMethod' => $this->restMethod(),
        ];
    }
}
