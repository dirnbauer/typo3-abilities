<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Permission;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The backend user the current request acts as. Every surface boots one
 * before the executor runs (backend module: the session, REST: the token's
 * user, webhook: the reaction's impersonated user, CLI: _cli_, MCP: the
 * server's user), so abilities and surfaces read it from here instead of
 * poking at globals.
 */
final class BackendUserContext
{
    public static function current(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication && self::uidOf($user) !== null ? $user : null;
    }

    public static function currentUid(): ?int
    {
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication ? self::uidOf($user) : null;
    }

    /**
     * Denial message for checkPermission() when no backend user is booted.
     */
    public static function missingUserMessage(string $abilityName): string
    {
        return sprintf(
            'Ability "%s" needs an authenticated backend user (backend session, REST token, webhook user, CLI _cli_ user or MCP session).',
            $abilityName,
        );
    }

    private static function uidOf(BackendUserAuthentication $user): ?int
    {
        $uid = is_array($user->user) ? ($user->user['uid'] ?? 0) : 0;

        return is_numeric($uid) && (int)$uid > 0 ? (int)$uid : null;
    }
}
