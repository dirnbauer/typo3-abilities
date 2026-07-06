<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Trace;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Abilities\Event\AbilityExecutedEvent;

/**
 * Persists one tx_abilities_trace row per execution attempt — ability,
 * surface, outcome, duration, requested input and acting backend user.
 * This is the abilities lane of the unified agent trace store (strategy
 * item 13); it answers "which agent ran what, with which permission, and
 * why did it (not) work".
 *
 * Recording must never break execution: failures to write are swallowed.
 */
#[AsEventListener(identifier: 'abilities/trace-recorder')]
final class TraceRecorder
{
    private const MAX_TEXT_LENGTH = 65000;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function __invoke(AbilityExecutedEvent $event): void
    {
        try {
            $this->connectionPool->getConnectionForTable('tx_abilities_trace')->insert('tx_abilities_trace', [
                'pid' => 0,
                'crdate' => time(),
                'ability' => $event->definition->name,
                'surface' => $event->context->surface,
                'ok' => $event->result->ok ? 1 : 0,
                'error_code' => (string)$event->result->errorCode,
                'error' => mb_substr((string)$event->result->error, 0, self::MAX_TEXT_LENGTH),
                'input' => mb_substr(
                    json_encode($event->input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}',
                    0,
                    self::MAX_TEXT_LENGTH,
                ),
                'duration_ms' => (int)round($event->durationMs),
                'be_user' => $this->currentBackendUserId(),
            ]);
        } catch (\Throwable) {
            // Tracing is an observer, never a gate: a missing table (schema
            // not yet updated) or unavailable connection must not fail the
            // ability execution itself.
        }
    }

    private function currentBackendUserId(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        $uid = $backendUser->user['uid'] ?? 0;

        return is_numeric($uid) ? (int)$uid : 0;
    }
}
