<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Permission\BackendUserContext;
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
final readonly class AbilitiesAjaxController
{
    public function __construct(
        private AbilitiesRegistry $registry,
        private AbilityExecutor $executor,
        private CategoryRegistry $categories,
        private AbilityCatalog $catalog,
        private BackendUserScopeResolver $scopeResolver,
        private TokenService $tokenService,
        private PolicyProvider $policyProvider,
        private TraceRepository $traces,
        private TraceRecorder $traceRecorder,
    ) {}

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $definitions = array_values(array_map(
            $this->withPolicy(...),
            $this->registry->getDefinitions(self::filter($query, 'category'), self::filter($query, 'surface')),
        ));

        return new JsonResponse(['abilities' => $definitions, 'total' => count($definitions)]);
    }

    public function describe(ServerRequestInterface $request): ResponseInterface
    {
        $name = self::filter($request->getQueryParams(), 'name') ?? '';
        if (!$this->registry->has($name)) {
            return new JsonResponse(['error' => 'Unknown ability.', 'errorCode' => AbilityErrorCode::NotFound->value], 404);
        }

        return new JsonResponse([
            ...$this->registry->describe($name),
            'policy' => $this->withPolicy($this->registry->getDefinition($name))['policy'],
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

    public function catalog(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        return new JsonResponse($this->catalog->toArray(
            self::filter($query, 'source'),
            self::filter($query, 'surface'),
            self::filter($query, 'search') ?? '',
        ));
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
        $user = BackendUserContext::current();
        $backendUserUid = BackendUserContext::currentUid();
        if ($user === null || $backendUserUid === null) {
            return new JsonResponse(['error' => 'No backend user.'], 403);
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($name === '') {
            return new JsonResponse(['error' => 'A token needs a name.'], 400);
        }

        $rawScopes = $body['scopes'] ?? [];
        if (is_string($rawScopes)) {
            $rawScopes = GeneralUtility::trimExplode(',', $rawScopes, true);
        }
        $scopes = is_array($rawScopes)
            ? array_values(array_filter(array_map(static fn(mixed $scope): string => is_string($scope) ? trim($scope) : '', $rawScopes)))
            : [];

        $expiresInDays = $body['expiresInDays'] ?? null;
        $expiresAt = is_numeric($expiresInDays) && (int)$expiresInDays > 0 ? time() + (int)$expiresInDays * 86400 : null;

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
        $ok = match ($query['ok'] ?? '') {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
        $limit = is_numeric($query['limit'] ?? null) ? (int)$query['limit'] : TraceRepository::DEFAULT_LIMIT;

        $traces = $this->traces->findLatest(self::filter($query, 'ability'), self::filter($query, 'surface'), $ok, $limit);

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
            $this->context(filter_var($body['approveReview'] ?? false, FILTER_VALIDATE_BOOLEAN)),
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
        $decision = $this->policyProvider->get()->decide($definition, $this->context());

        return [
            ...$definition->toArray(),
            'policy' => [
                'allowed' => $decision->allowed,
                'reviewRequired' => $decision->reviewRequired,
                'reason' => $decision->reason,
            ],
        ];
    }

    private function context(bool $reviewApproved = false): ExecutionContext
    {
        $user = BackendUserContext::current();

        return ExecutionContext::backend(
            reviewApproved: $reviewApproved,
            grantedScopes: $user === null ? [] : $this->scopeResolver->resolveForUser($user),
            backendUserUid: BackendUserContext::currentUid(),
        );
    }

    /**
     * @param array<mixed> $query
     */
    private static function filter(array $query, string $key): ?string
    {
        return is_string($query[$key] ?? null) && $query[$key] !== '' ? $query[$key] : null;
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
