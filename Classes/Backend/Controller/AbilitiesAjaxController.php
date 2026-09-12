<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Security\Token;
use Webconsulting\Abilities\Security\TokenService;
use Webconsulting\Abilities\Trace\TraceRecorder;
use Webconsulting\Abilities\Trace\TraceRepository;

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
        private readonly PolicyProvider $policyProvider,
        private readonly TraceRepository $traces,
        private readonly TraceRecorder $traceRecorder,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $category = is_string($query['category'] ?? null) && $query['category'] !== '' ? $query['category'] : null;
        $surface = is_string($query['surface'] ?? null) && $query['surface'] !== '' ? $query['surface'] : null;

        $definitions = array_values(array_map(
            fn(AbilityDefinition $definition): array => $this->withPolicy($definition),
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
            ...$this->withPolicy($this->registry->getDefinition($name)),
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

        return new JsonResponse([
            'tokens' => $tokens,
            'total' => count($tokens),
            'scopes' => $this->registry->getDeclaredScopes(),
        ]);
    }

    /**
     * Issue a token for the acting backend user. A token can never widen the
     * user's own grants (REST intersects token scopes with user scopes), and
     * the plaintext is returned exactly once — only its hash is stored.
     */
    public function tokenCreate(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $user = $this->currentUser();
        $backendUserUid = $this->currentUserUid();
        if ($user === null || $backendUserUid === null) {
            return new JsonResponse(['error' => 'No backend user.'], 403);
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '') {
            return new JsonResponse(['error' => 'A token needs a name.'], 400);
        }

        $scopes = [];
        $rawScopes = $body['scopes'] ?? [];
        if (is_string($rawScopes)) {
            $rawScopes = GeneralUtility::trimExplode(',', $rawScopes, true);
        }
        if (is_array($rawScopes)) {
            foreach ($rawScopes as $scope) {
                if (is_string($scope) && trim($scope) !== '') {
                    $scopes[] = trim($scope);
                }
            }
        }

        $expiresInDays = $body['expiresInDays'] ?? null;
        $expiresAt = null;
        if (is_numeric($expiresInDays) && (int)$expiresInDays > 0) {
            $expiresAt = time() + (int)$expiresInDays * 86400;
        }

        $issued = $this->tokenService->create($name, $backendUserUid, $scopes, $expiresAt);
        $username = is_array($user->user) ? ($user->user['username'] ?? null) : null;

        return new JsonResponse([
            ...$issued->token->toArray(),
            'username' => is_string($username) ? $username : '',
            'effectiveScopes' => BackendUserScopeResolver::intersect(
                $issued->token->scopes,
                $this->scopeResolver->resolveForUser($user),
            ),
            // The one and only time the plaintext exists outside the client.
            'token' => $issued->plaintext,
        ], 201);
    }

    public function tokenRevoke(ServerRequestInterface $request): ResponseInterface
    {
        $uid = $this->body($request)['uid'] ?? null;
        if (!is_numeric($uid) || (int)$uid <= 0) {
            return new JsonResponse(['error' => 'A token uid is required.'], 400);
        }

        if (!$this->tokenService->revoke((int)$uid)) {
            return new JsonResponse(['error' => sprintf('No active token with uid %d.', (int)$uid)], 404);
        }

        return new JsonResponse(['revoked' => (int)$uid]);
    }

    public function traceList(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ability = is_string($query['ability'] ?? null) && $query['ability'] !== '' ? $query['ability'] : null;
        $surface = is_string($query['surface'] ?? null) && $query['surface'] !== '' ? $query['surface'] : null;
        $ok = match ($query['ok'] ?? '') {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        $limit = is_numeric($query['limit'] ?? null) ? (int)$query['limit'] : TraceRepository::DEFAULT_LIMIT;

        $traces = $this->traces->findLatest($ability, $surface, $ok, $limit);

        return new JsonResponse([
            'traces' => $traces,
            'total' => count($traces),
            'totalStored' => $this->traces->countAll(),
            'surfaces' => $this->traces->surfacesInUse(),
        ]);
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

        return new JsonResponse(
            [...$result->toArray(), 'traceUid' => $this->traceRecorder->lastTraceUid()],
            $result->httpStatus(),
        );
    }

    /**
     * The registry entry plus what the site policy would decide for it right
     * now, so the module can show the review checkbox (and a denial) before
     * anybody presses "Execute" instead of after.
     *
     * @return array<string, mixed>
     */
    private function withPolicy(AbilityDefinition $definition): array
    {
        $decision = $this->policyProvider->get()->decide(
            $definition,
            ExecutionContext::backend(grantedScopes: $this->currentUserScopes(), backendUserUid: $this->currentUserUid()),
        );

        return [
            ...$definition->toArray(),
            'policy' => [
                'allowed' => $decision->allowed,
                'reviewRequired' => $decision->reviewRequired,
                'reason' => $decision->reason,
            ],
        ];
    }

    private function currentUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && is_array($backendUser->user) ? $backendUser : null;
    }

    /**
     * @return list<string>
     */
    private function currentUserScopes(): array
    {
        $user = $this->currentUser();

        return $user === null ? [] : $this->scopeResolver->resolveForUser($user);
    }

    private function currentUserUid(): ?int
    {
        $uid = $this->currentUser()?->user['uid'] ?? 0;

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
