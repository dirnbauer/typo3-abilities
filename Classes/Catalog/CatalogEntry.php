<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog;

/**
 * One entry of the ability catalogue: one unit of functionality this
 * installation can perform, described uniformly whatever its origin — a
 * registered ability, an MCP tool, an agent skill, a REST or webhook endpoint,
 * a console command. The catalogue is what an AI agent reads first: every
 * entry says what it is, how risky it is and how to invoke it from each
 * surface.
 */
final readonly class CatalogEntry
{
    public const SOURCE_ABILITIES = 'abilities';
    public const SOURCE_MCP = 'mcp';
    public const SOURCE_SKILLS = 'skills';
    public const SOURCE_REST = 'rest';
    public const SOURCE_CLI = 'cli';

    /**
     * @param string $id "namespace/name", unique across the catalogue (abilities keep their name; other sources are prefixed, e.g. "mcp/GetPage", "cli/cache:flush")
     * @param string $title Short label, written for an LLM consumer as much as for a human
     * @param string $description What it does, when to use it
     * @param string $source One of the SOURCE_* slugs
     * @param list<string> $surfaces Where it can be invoked from ("mcp", "cli", "rest", "webhook", "scheduler", "skills", "php")
     * @param array<string, mixed> $inputSchema JSON Schema of the input; [] when unknown
     * @param array{readonly: bool, destructive: bool, idempotent: bool} $annotations WordPress-style annotations
     * @param array<string, string> $invocations surface => how to invoke it there (a command line, a tool name, "METHOD /path", a PHP call)
     * @param array<string, mixed> $meta Source-specific facts (category, scopes, risk tier, declared abilities, …)
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $source,
        public array $surfaces,
        public array $inputSchema,
        public array $annotations,
        public array $invocations,
        public array $meta = [],
    ) {}

    /**
     * @return array{readonly: bool, destructive: bool, idempotent: bool}
     */
    public static function annotations(bool $readonly = false, bool $destructive = false, bool $idempotent = false): array
    {
        return ['readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent];
    }

    public function matches(string $search): bool
    {
        $haystack = strtolower($this->id . ' ' . $this->title . ' ' . $this->description);

        return $search === '' || str_contains($haystack, strtolower($search));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'surfaces' => $this->surfaces,
            'inputSchema' => $this->inputSchema === [] ? new \stdClass() : $this->inputSchema,
            'annotations' => $this->annotations,
            'invocations' => $this->invocations === [] ? new \stdClass() : $this->invocations,
            'meta' => $this->meta === [] ? new \stdClass() : $this->meta,
        ];
    }
}
