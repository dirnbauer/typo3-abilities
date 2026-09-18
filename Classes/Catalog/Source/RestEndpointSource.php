<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use SGalinski\SgApicore\Configuration\ExtensionConfiguration as ApiCoreConfiguration;
use SGalinski\SgApicore\Service\EndpointDiscoveryService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Catalog\CapabilitySourceInterface;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Http\RestConfiguration;
use Webconsulting\Abilities\Reaction\RunAbilityReaction;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * HTTP endpoints of the installation:
 *  - the abilities REST projection itself (when enabled),
 *  - every EXT:reactions webhook (sys_reaction), a "Run ability" reaction
 *    carrying the ability's schema and annotations,
 *  - every sgalinski/sg-apicore endpoint discovered from its #[ApiRoute]
 *    attributes.
 * EXT:reactions and sg-apicore are optional; absent ones yield nothing.
 */
final class RestEndpointSource implements CapabilitySourceInterface
{
    public function __construct(
        private readonly RestConfiguration $rest,
        private readonly AbilitiesRegistry $registry,
        private readonly ConnectionPool $connectionPool,
        private readonly ?EndpointDiscoveryService $apiCoreEndpoints = null,
        private readonly ?ApiCoreConfiguration $apiCoreConfiguration = null,
    ) {}

    public function getSource(): string
    {
        return CapabilityEntry::SOURCE_REST;
    }

    public function getCapabilities(): iterable
    {
        yield from $this->abilitiesApi();
        yield from $this->reactions();
        yield from $this->apiCore();
    }

    /**
     * @return iterable<CapabilityEntry>
     */
    private function abilitiesApi(): iterable
    {
        if (!$this->rest->enabled) {
            return;
        }
        $base = $this->rest->basePath;
        $routes = [
            ['abilities', 'GET', '/abilities', 'List abilities', 'Lists the abilities exposed to REST with their full definitions; filter with ?category=, paginate with ?page= and ?per_page=.'],
            ['abilities-describe', 'GET', '/abilities/{namespace}/{name}', 'Describe ability', 'The full contract of one ability including its input and output JSON Schemas.'],
            ['abilities-run', 'GET|POST|DELETE', '/abilities/{namespace}/{name}/run', 'Run ability', 'Runs an ability through the governed pipeline; the method follows the annotations (read-only GET, destructive DELETE, otherwise POST). Input as query parameters, ?input=<json> or a JSON body {"input": {...}}.'],
            ['categories', 'GET', '/categories', 'List categories', 'Every ability category, flagged whether an ability uses it.'],
            ['categories-describe', 'GET', '/categories/{slug}', 'Describe category', 'One category with the names of its abilities.'],
            ['catalog', 'GET', '/catalog', 'Capability catalogue', 'Everything the installation can do — abilities, MCP tools, skills, endpoints, commands — filterable with ?source=, ?surface= and ?search=.'],
        ];
        foreach ($routes as [$slug, $methods, $path, $title, $description]) {
            yield new CapabilityEntry(
                id: 'rest/' . $slug,
                title: $title,
                description: $description . ' Authenticate with "Authorization: Bearer <token>" (abilities:token:create) or a same-origin backend session.',
                source: CapabilityEntry::SOURCE_REST,
                surfaces: [ExecutionContext::SURFACE_REST],
                inputSchema: [],
                annotations: CapabilityEntry::annotations(readonly: $slug !== 'abilities-run'),
                invocations: [ExecutionContext::SURFACE_REST => $methods . ' ' . $base . $path],
                meta: ['auth' => 'bearer token or backend session'],
            );
        }
    }

    /**
     * @return iterable<CapabilityEntry>
     */
    private function reactions(): iterable
    {
        if (!ExtensionManagementUtility::isLoaded('reactions')) {
            return;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_reaction');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('*')
            ->from('sys_reaction')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disabled', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('identifier')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $entry = $this->reactionEntry($row);
            if ($entry !== null) {
                yield $entry;
            }
        }
    }

    /**
     * @param array<string, mixed> $row a sys_reaction row
     */
    public function reactionEntry(array $row): ?CapabilityEntry
    {
        $identifier = is_string($row['identifier'] ?? null) ? $row['identifier'] : '';
        if ($identifier === '') {
            return null;
        }
        $type = is_string($row['reaction_type'] ?? null) ? $row['reaction_type'] : '';
        $abilityName = is_string($row[RunAbilityReaction::FIELD_ABILITY] ?? null) ? $row[RunAbilityReaction::FIELD_ABILITY] : '';
        $ability = $type === RunAbilityReaction::TYPE && $this->registry->has($abilityName) ? $abilityName : null;
        $definition = $ability === null ? null : $this->registry->getDefinition($ability);

        $description = is_string($row['description'] ?? null) ? trim($row['description']) : '';
        if ($definition !== null) {
            $description = trim($description . ' Runs the ability ' . $definition->name . ': ' . $definition->description);
        }

        return new CapabilityEntry(
            id: 'webhook/' . $identifier,
            title: is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : $identifier,
            description: $description === '' ? sprintf('EXT:reactions webhook of type "%s".', $type) : $description,
            source: CapabilityEntry::SOURCE_REST,
            surfaces: [ExecutionContext::SURFACE_WEBHOOK],
            inputSchema: $ability === null ? [] : $this->registry->get($ability)->getInputSchema(),
            annotations: $definition === null
                ? CapabilityEntry::annotations()
                : CapabilityEntry::annotations($definition->isReadOnly(), $definition->destructive, $definition->idempotent),
            invocations: [
                ExecutionContext::SURFACE_WEBHOOK => sprintf('POST /typo3/reaction/%s with header "x-api-key: <secret>" and a JSON body', $identifier),
            ],
            meta: array_filter([
                'reactionType' => $type,
                'impersonateUser' => (int)($row['impersonate_user'] ?? 0),
                'ability' => $ability,
            ], static fn(mixed $value): bool => $value !== null && $value !== 0 && $value !== ''),
        );
    }

    /**
     * @return iterable<CapabilityEntry>
     */
    private function apiCore(): iterable
    {
        if ($this->apiCoreEndpoints === null || !class_exists(EndpointDiscoveryService::class)) {
            return;
        }
        $prefix = $this->apiCoreConfiguration !== null && class_exists(ApiCoreConfiguration::class)
            ? $this->apiCoreConfiguration->getApiPathPrefix()
            : '/api/';
        foreach ($this->apiCoreEndpoints->getAllEndpoints() as $endpoint) {
            if (is_array($endpoint)) {
                yield self::apiCoreEntry($endpoint, $prefix);
            }
        }
    }

    /**
     * @param array<string, mixed> $endpoint one EndpointDiscoveryService::getAllEndpoints() row
     */
    public static function apiCoreEntry(array $endpoint, string $prefix = '/api/'): CapabilityEntry
    {
        $path = is_string($endpoint['path'] ?? null) ? $endpoint['path'] : '/';
        $apiIds = is_array($endpoint['apiId'] ?? null) ? $endpoint['apiId'] : [$endpoint['apiId'] ?? null];
        $apiId = is_string($apiIds[0] ?? null) ? $apiIds[0] : '';
        $versions = is_array($endpoint['version'] ?? null) ? $endpoint['version'] : [$endpoint['version'] ?? null];
        $version = is_string($versions[0] ?? null) ? $versions[0] : '';
        $methods = is_array($endpoint['methods'] ?? null) ? array_values(array_filter($endpoint['methods'], is_string(...))) : ['GET'];
        $methodList = strtoupper(implode('|', $methods === [] ? ['GET'] : $methods));
        $summary = is_string($endpoint['summary'] ?? null) ? $endpoint['summary'] : '';
        $description = is_string($endpoint['description'] ?? null) ? $endpoint['description'] : '';

        $url = rtrim($prefix, '/') . '/' . implode('/', array_filter([$apiId, $version, trim($path, '/')], static fn(string $part): bool => $part !== ''));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(($apiId === '' ? '' : $apiId . '-') . $path)) ?? '', '-');
        $readonly = $methods === [] || array_diff(array_map(strtoupper(...), $methods), ['GET', 'HEAD', 'OPTIONS']) === [];
        $destructive = in_array('DELETE', array_map(strtoupper(...), $methods), true);

        return new CapabilityEntry(
            id: 'api/' . ($slug === '' ? 'root' : $slug),
            title: $summary !== '' ? $summary : $methodList . ' ' . $path,
            description: $description !== '' ? $description : ($summary !== '' ? $summary : sprintf('sg-apicore endpoint %s %s.', $methodList, $path)),
            source: CapabilityEntry::SOURCE_REST,
            surfaces: [ExecutionContext::SURFACE_REST],
            inputSchema: self::apiCoreSchema($endpoint),
            annotations: CapabilityEntry::annotations($readonly, $destructive, $readonly),
            invocations: [ExecutionContext::SURFACE_REST => $methodList . ' ' . $url],
            meta: array_filter([
                'apiId' => $apiId,
                'version' => $version,
                'authMode' => $endpoint['authMode'] ?? null,
                'scopes' => is_array($endpoint['scopes'] ?? null) ? $endpoint['scopes'] : [],
                'provider' => 'sg-apicore',
            ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []),
        );
    }

    /**
     * A JSON Schema from the endpoint's declared query, body and path parameters.
     *
     * @param array<string, mixed> $endpoint
     * @return array<string, mixed>
     */
    private static function apiCoreSchema(array $endpoint): array
    {
        $properties = [];
        $required = [];
        foreach (['pathParams', 'queryParams', 'bodyParams'] as $group) {
            $parameters = is_array($endpoint[$group] ?? null) ? $endpoint[$group] : [];
            foreach ($parameters as $parameter) {
                $name = is_object($parameter) ? ($parameter->name ?? null) : ($parameter['name'] ?? null);
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $type = is_object($parameter) ? ($parameter->type ?? 'string') : ($parameter['type'] ?? 'string');
                $descriptionValue = is_object($parameter) ? ($parameter->description ?? null) : ($parameter['description'] ?? null);
                $properties[$name] = array_filter([
                    'type' => is_string($type) ? $type : 'string',
                    'description' => is_string($descriptionValue) ? $descriptionValue : null,
                ]);
                $isRequired = is_object($parameter) ? ($parameter->required ?? false) : ($parameter['required'] ?? false);
                if ($group === 'pathParams' || $isRequired === true) {
                    $required[] = $name;
                }
            }
        }
        if ($properties === []) {
            return [];
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }
}
