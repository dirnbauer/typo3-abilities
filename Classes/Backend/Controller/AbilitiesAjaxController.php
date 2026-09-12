<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Security\Token;
use Webconsulting\Abilities\Security\TokenService;

/**
 * Backend AJAX endpoints for the abilities module and the client.js ES
 * module. All run inside the authenticated backend (session-guarded,
 * access inherited from the module), so they carry no token: the acting
 * backend user is the identity. Runs execute on the "backend" surface with
 * the scopes resolved from the user's be_groups (admins: "*"); policy and
 * each ability's checkPermission() govern as on every other surface.
 */
final class AbilitiesAjaxController
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
        private readonly CategoryRegistry $categories,
        private readonly BackendUserScopeResolver $scopeResolver,
        private readonly TokenService $tokenService,
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = is_string($query['category'] ?? null) && $query['category'] !== '' ? $query['category'] : null;
        $surface = is_string($query['surface'] ?? null) && $query['surface'] !== '' ? $query['surface'] : null;

        $definitions = array_values(array_map(
            static fn(AbilityDefinition $definition): array => $definition->toArray(),
            $this->registry->getDefinitions($category, $surface),
        ));

        return new JsonResponse(['abilities' => $definitions, 'total' => count($definitions)]);
    }

    public function describe(ServerRequestInterface $request): ResponseInterface
    {
        $nameParam = $request->getQueryParams()['name'] ?? null;
        $name = is_string($nameParam) ? $nameParam : '';
        if (!$this->registry->has($name)) {
            return new JsonResponse(['error' => 'Unknown ability.', 'errorCode' => AbilityErrorCode::NotFound->value], 404);
        }

        $ability = $this->registry->get($name);

        return new JsonResponse([
            ...$this->registry->getDefinition($name)->toArray(),
            'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
            'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
        ]);
    }

    public function categories(ServerRequestInterface $request): ResponseInterface
    {
        $inUse = array_fill_keys($this->registry->getCategoriesInUse(), true);
        $categories = array_values(array_map(
            static fn(AbilityCategory $category): array => [
                ...$category->toArray(),
                'inUse' => isset($inUse[$category->slug]),
            ],
            $this->categories->all(),
        ));

        return new JsonResponse(['categories' => $categories, 'total' => count($categories)]);
    }

    public function tokens(ServerRequestInterface $request): ResponseInterface
    {
        $tokens = array_map(static fn(Token $token): array => $token->toArray(), $this->tokenService->list());

        return new JsonResponse(['tokens' => $tokens, 'total' => count($tokens)]);
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        if (!$this->registry->has($name)) {
            return new JsonResponse(
                ['ok' => false, 'errorCode' => AbilityErrorCode::NotFound->value, 'error' => 'Unknown ability.'],
                404,
            );
        }

        $input = $body['input'] ?? [];
        if (!is_array($input)) {
            return new JsonResponse(
                ['ok' => false, 'errorCode' => AbilityErrorCode::InvalidInput->value, 'error' => 'input must be an object.'],
                400,
            );
        }
        $objectInput = [];
        foreach ($input as $key => $value) {
            $objectInput[(string)$key] = $value;
        }

        // filter_var, not (bool): a form-encoded "approveReview=false" is the
        // string "false", and (bool) "false" === true would silently flip an
        // unapproved high-risk run into an approved one.
        $result = $this->executor->execute(
            $this->registry->get($name),
            $objectInput,
            ExecutionContext::backend(
                reviewApproved: filter_var($body['approveReview'] ?? false, FILTER_VALIDATE_BOOLEAN),
                grantedScopes: $this->currentUserScopes(),
                backendUserUid: $this->currentUserUid(),
            ),
            $this->registry->getDefinition($name),
        );

        return new JsonResponse($result->toArray(), $result->httpStatus());
    }

    /**
     * @return list<string>
     */
    private function currentUserScopes(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $this->scopeResolver->resolveForUser($backendUser) : [];
    }

    private function currentUserUid(): ?int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $uid = $backendUser instanceof BackendUserAuthentication ? ($backendUser->user['uid'] ?? 0) : 0;

        return is_numeric($uid) && (int)$uid > 0 ? (int)$uid : null;
    }

    /**
     * @return array<mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $decoded = json_decode((string)$request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
