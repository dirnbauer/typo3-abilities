<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Security\TokenService;

/**
 * Authenticates REST requests and boots the acting TYPO3 backend user into
 * $GLOBALS['BE_USER'] (group data, workspace hint, language, context aspect)
 * so abilities run with real permissions — the pattern of sg_apicore's
 * BackendBearerOpaqueTokenProvider and the MCP server's backend-user bootstrap.
 *
 * Two identities are accepted:
 *  - "Authorization: Bearer <token>": an abilities token (TokenService);
 *    effective scopes = token scopes ∩ the user's be_groups scopes
 *  - an existing backend session cookie (same-origin clients such as the
 *    backend module or client.js); non-GET requests must additionally send
 *    X-Requested-With as a CSRF guard. Scopes = the user's be_groups scopes.
 */
final class RestAuthenticator
{
    public const WORKSPACE_HEADER = 'X-TYPO3-Workspace';

    public function __construct(
        private readonly TokenService $tokenService,
        private readonly BackendUserScopeResolver $scopeResolver,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Context $context,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?RestIdentity
    {
        $bearer = self::extractBearerToken($request);
        if ($bearer !== '') {
            return $this->authenticateToken($bearer, $request);
        }

        if (isset($request->getCookieParams()[BackendUserAuthentication::getCookieName()])) {
            return $this->authenticateSession($request);
        }

        return null;
    }

    public static function extractBearerToken(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            $serverParams = $request->getServerParams();
            $fallback = $serverParams['HTTP_AUTHORIZATION'] ?? $serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            $header = is_string($fallback) ? $fallback : '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    private function authenticateToken(string $plaintext, ServerRequestInterface $request): ?RestIdentity
    {
        $token = $this->tokenService->authenticate($plaintext);
        if ($token === null) {
            return null;
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($token->backendUserUid);
        if (!is_array($backendUser->user) || (int)($backendUser->user['uid'] ?? 0) <= 0) {
            return null;
        }

        // Stateless request: give DataHandler-adjacent code an in-memory session.
        $backendUser->initializeUserSessionManager();
        $this->establish($backendUser, $request);

        return new RestIdentity(
            backendUserUid: $token->backendUserUid,
            username: $this->username($backendUser),
            scopes: BackendUserScopeResolver::intersect($token->scopes, $this->scopeResolver->resolveForUser($backendUser)),
            via: RestIdentity::VIA_TOKEN,
            tokenUid: $token->uid,
        );
    }

    private function authenticateSession(ServerRequestInterface $request): ?RestIdentity
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && $request->getHeaderLine('X-Requested-With') === ''
        ) {
            return null;
        }

        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER'] = $backendUser;
        $backendUser->start($request);
        $uid = is_array($backendUser->user) ? (int)($backendUser->user['uid'] ?? 0) : 0;
        if ($uid <= 0) {
            unset($GLOBALS['BE_USER']);

            return null;
        }

        $this->establish($backendUser, $request);

        return new RestIdentity(
            backendUserUid: $uid,
            username: $this->username($backendUser),
            scopes: $this->scopeResolver->resolveForUser($backendUser),
            via: RestIdentity::VIA_SESSION,
        );
    }

    private function establish(BackendUserAuthentication $backendUser, ServerRequestInterface $request): void
    {
        $GLOBALS['BE_USER'] = $backendUser;
        $backendUser->fetchGroupData();

        $workspace = $request->getHeaderLine(self::WORKSPACE_HEADER);
        if ($workspace !== '' && is_numeric($workspace)) {
            $backendUser->setTemporaryWorkspace((int)$workspace);
        }

        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $this->context->setAspect('backend.user', new UserAspect($backendUser));
        Bootstrap::loadExtTables();
    }

    private function username(BackendUserAuthentication $backendUser): string
    {
        $username = is_array($backendUser->user) ? ($backendUser->user['username'] ?? '') : '';

        return is_string($username) ? $username : '';
    }
}
