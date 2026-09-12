<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * The REST projection's endpoints: generated views of the registry, never
 * hand-rolled logic. Every run goes through the same governed pipeline as
 * CLI, MCP and the backend module; review-gated abilities are never
 * approvable over REST (a bearer token is not a human in the loop) and
 * answer 409 ability_review_required.
 */
final class RestRequestHandler
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
        private readonly CategoryRegistry $categories,
        private readonly RestResponseFactory $responses,
        private readonly RestInputMapper $inputMapper,
    ) {
    }

    public function handle(RestRoute $route, ServerRequestInterface $request, RestIdentity $identity): ResponseInterface
    {
        if ($route->name !== RestRoute::RUN && !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->responses->error(
                RestResponseFactory::ERROR_INVALID_METHOD,
                sprintf('%s does not accept %s.', $route->name, $request->getMethod()),
                405,
                ['Allow' => 'GET'],
            );
        }

        return match ($route->name) {
            RestRoute::LIST => $this->list($request),
            RestRoute::DESCRIBE => $this->describe($route),
            RestRoute::RUN => $this->run($route, $request, $identity),
            RestRoute::CATEGORIES => $this->categories(),
            RestRoute::CATEGORY => $this->category($route),
            default => $this->responses->error(RestResponseFactory::ERROR_NOT_FOUND, 'No such endpoint.', 404),
        };
    }

    private function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = is_string($query['category'] ?? null) && $query['category'] !== '' ? $query['category'] : null;
        $page = max(1, (int)(is_numeric($query['page'] ?? null) ? $query['page'] : 1));
        $perPage = (int)(is_numeric($query['per_page'] ?? null) ? $query['per_page'] : self::DEFAULT_PER_PAGE);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $definitions = array_values($this->registry->getDefinitions($category, ExecutionContext::SURFACE_REST));
        $total = count($definitions);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $pageItems = array_slice($definitions, ($page - 1) * $perPage, $perPage);

        return $this->responses->success(
            [
                'abilities' => array_map(static fn(AbilityDefinition $definition): array => $definition->toArray(), $pageItems),
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
            ],
            200,
            ['X-Total' => (string)$total, 'X-Total-Pages' => (string)$totalPages],
        );
    }

    private function describe(RestRoute $route): ResponseInterface
    {
        $definition = $this->exposedDefinition($route->param('ability'));
        if ($definition === null) {
            return $this->abilityNotFound($route->param('ability'));
        }
        $ability = $this->registry->get($definition->name);

        return $this->responses->success([
            ...$definition->toArray(),
            'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
            'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
        ]);
    }

    private function run(RestRoute $route, ServerRequestInterface $request, RestIdentity $identity): ResponseInterface
    {
        $definition = $this->exposedDefinition($route->param('ability'));
        if ($definition === null) {
            return $this->abilityNotFound($route->param('ability'));
        }

        $expectedMethod = $definition->restMethod();
        if (strtoupper($request->getMethod()) !== $expectedMethod) {
            return $this->responses->error(
                RestResponseFactory::ERROR_ABILITY_INVALID_METHOD,
                sprintf(
                    'Ability "%s" is %s and must be run with %s, not %s.',
                    $definition->name,
                    $definition->isReadOnly() ? 'read-only' : ($definition->destructive ? 'destructive' : 'a write operation'),
                    $expectedMethod,
                    strtoupper($request->getMethod()),
                ),
                405,
                ['Allow' => $expectedMethod],
            );
        }

        $ability = $this->registry->get($definition->name);
        try {
            $input = $this->inputMapper->fromRequest($request, $ability->getInputSchema());
        } catch (InvalidRestInputException $exception) {
            return $this->responses->error(AbilityErrorCode::InvalidInput->value, $exception->getMessage(), 400);
        }

        $result = $this->executor->execute($ability, $input, $identity->executionContext(), $definition);

        return $this->responses->fromResult($result);
    }

    private function categories(): ResponseInterface
    {
        $inUse = array_fill_keys($this->registry->getCategoriesInUse(), true);
        $categories = array_values(array_map(
            static fn(AbilityCategory $category): array => [...$category->toArray(), 'inUse' => isset($inUse[$category->slug])],
            $this->categories->all(),
        ));

        return $this->responses->success(['categories' => $categories, 'total' => count($categories)]);
    }

    private function category(RestRoute $route): ResponseInterface
    {
        $slug = $route->param('slug');
        if (!$this->categories->has($slug)) {
            return $this->responses->error(
                RestResponseFactory::ERROR_CATEGORY_NOT_FOUND,
                sprintf('Unknown ability category "%s".', $slug),
                404,
            );
        }

        $abilities = array_keys($this->registry->getDefinitions($slug, ExecutionContext::SURFACE_REST));

        return $this->responses->success([
            ...$this->categories->get($slug)->toArray(),
            'abilities' => $abilities,
        ]);
    }

    private function exposedDefinition(string $name): ?AbilityDefinition
    {
        if (!$this->registry->has($name)) {
            return null;
        }
        $definition = $this->registry->getDefinition($name);

        return $definition->isExposedTo(ExecutionContext::SURFACE_REST) ? $definition : null;
    }

    private function abilityNotFound(string $name): ResponseInterface
    {
        return $this->responses->error(
            RestResponseFactory::ERROR_ABILITY_NOT_FOUND,
            sprintf('No ability "%s" is exposed to the REST surface.', $name),
            404,
        );
    }
}
