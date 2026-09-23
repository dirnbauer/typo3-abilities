<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;

/**
 * Agent skills (SKILL.md records) stored by netresearch/nr-llm
 * (tx_nrllm_skill) or webconsulting/skillflow (tx_skillflow_skill): name,
 * description, the abilities the front matter declares and the tools the
 * skill is allowed to use. Both extensions are optional — a table that does
 * not exist yields nothing.
 */
final readonly class SkillSource implements CatalogSourceInterface
{
    public const string SURFACE_SKILLS = 'skills';

    /**
     * table => [identifier column, title column, front-matter column, enable conditions]
     *
     * @var array<string, array{string, string, string, array<string, int>}>
     */
    private const array TABLES = [
        'tx_nrllm_skill' => ['name', 'name', 'raw_frontmatter', ['deleted' => 0, 'hidden' => 0, 'enabled' => 1]],
        'tx_skillflow_skill' => ['identifier', 'title', 'metadata', ['deleted' => 0, 'hidden' => 0]],
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSource(): string
    {
        return CatalogEntry::SOURCE_SKILLS;
    }

    public function getEntries(): iterable
    {
        foreach (self::TABLES as $table => [$identifierColumn, $titleColumn, $frontMatterColumn, $conditions]) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            if (!$connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->select('uid', $identifierColumn, $titleColumn, 'description', $frontMatterColumn, 'allowed_tools')
                ->from($table)
                ->orderBy($identifierColumn);
            foreach ($conditions as $column => $value) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($column, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)),
                );
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $entry = self::entry($table, $row);
                if ($entry !== null) {
                    yield $entry;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function entry(string $table, array $row): ?CatalogEntry
    {
        [$identifierColumn, $titleColumn, $frontMatterColumn] = self::TABLES[$table] ?? ['identifier', 'title', 'metadata'];
        $identifier = is_string($row[$identifierColumn] ?? null) ? trim($row[$identifierColumn]) : '';
        if ($identifier === '') {
            return null;
        }
        $frontMatter = self::decode($row[$frontMatterColumn] ?? null);
        $description = is_string($frontMatter['description'] ?? null) && $frontMatter['description'] !== ''
            ? $frontMatter['description']
            : (is_string($row['description'] ?? null) ? $row['description'] : '');
        $abilities = self::stringList($frontMatter['abilities'] ?? null);
        $allowedTools = self::stringList(self::decode($row['allowed_tools'] ?? null, true));

        $invocation = sprintf('Load skill "%s" into the agent context', $identifier);
        if ($abilities !== []) {
            $invocation .= sprintf('; it uses the abilities %s', implode(', ', $abilities));
        }

        return new CatalogEntry(
            id: 'skill/' . $identifier,
            title: is_string($row[$titleColumn] ?? null) && $row[$titleColumn] !== '' ? $row[$titleColumn] : $identifier,
            description: $description,
            source: CatalogEntry::SOURCE_SKILLS,
            surfaces: [self::SURFACE_SKILLS],
            inputSchema: [],
            annotations: CatalogEntry::annotations(),
            invocations: [self::SURFACE_SKILLS => $invocation],
            meta: array_filter([
                'table' => $table,
                'uid' => (int)($row['uid'] ?? 0),
                'abilities' => $abilities,
                'allowedTools' => $allowedTools,
            ], static fn(mixed $value): bool => $value !== [] && $value !== 0),
        );
    }

    /**
     * Front matter and tool lists are stored as JSON — sometimes JSON-encoded
     * twice — or, for tool lists, as a comma-separated string.
     *
     * @return array<mixed>
     */
    private static function decode(mixed $value, bool $commaList = false): array
    {
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            $decoded = json_decode($trimmed, true);
            if ($decoded === null) {
                return $commaList ? array_values(array_filter(array_map(trim(...), explode(',', $trimmed)))) : [];
            }
            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    }
}
