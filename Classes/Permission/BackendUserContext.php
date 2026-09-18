<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability\Support;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The backend user an ability acts as. Every surface boots one before the
 * executor runs (backend module: the session, REST: the token's user, CLI:
 * _cli_, MCP: the server's user), so abilities that call the DataHandler
 * or check page permissions read it from here instead of poking at globals.
 */
final class BackendUserContext
{
    public static function current(): ?BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || !is_array($user->user)) {
            return null;
        }
        $uid = $user->user['uid'] ?? 0;

        return is_numeric($uid) && (int)$uid > 0 ? $user : null;
    }

    /**
     * Denial message for checkPermission() when no backend user is booted.
     */
    public static function missingUserMessage(string $abilityName): string
    {
        return sprintf(
            'Ability "%s" needs an authenticated backend user (backend session, REST token, CLI _cli_ user or MCP session).',
            $abilityName,
        );
    }
}
