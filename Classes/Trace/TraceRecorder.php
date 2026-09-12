<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Trace;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Abilities\Event\AfterAbilityExecutionEvent;

/**
 * Persists one tx_abilities_trace row per execution attempt — ability,
 * surface, outcome, duration, requested input and acting backend user.
 * This is the abilities lane of the unified agent trace store; it answers
 * "which agent ran what, with which permission, and why did it (not) work".
 *
 * Rows older than the configured retention (extension setting
 * traceRetentionDays, 0 = keep forever) are pruned opportunistically on a
 * small fraction of writes, so no scheduler task is required.
 *
 * Recording must never break execution: failures to write are swallowed.
 */
#[AsEventListener(identifier: 'abilities/trace-recorder')]
final class TraceRecorder
{
    public const TABLE = 'tx_abilities_trace';

    private const MAX_TEXT_LENGTH = 65000;
    private const PRUNE_PROBABILITY_PERCENT = 2;

    private ?int $lastTraceUid = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ?ExtensionConfiguration $extensionConfiguration = null,
    ) {}

    /**
     * Uid of the trace row written for the last execution in this request, or
     * null when none could be written. The backend module reports it next to
     * the result so an editor can look the run up in the Traces tab.
     */
    public function lastTraceUid(): ?int
    {
        return $this->lastTraceUid;
    }

    public function __invoke(AfterAbilityExecutionEvent $event): void
    {
        $this->lastTraceUid = null;

        try {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
            $connection->insert(self::TABLE, [
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
                'be_user' => $event->context->backendUserUid ?? $this->currentBackendUserId(),
            ]);
            $this->lastTraceUid = (int)$connection->lastInsertId() ?: null;

            if (random_int(1, 100) <= self::PRUNE_PROBABILITY_PERCENT) {
                $this->prune($connection);
            }
        } catch (\Throwable) {
            // Tracing is an observer, never a gate: a missing table (schema
            // not yet updated) or unavailable connection must not fail the
            // ability execution itself.
        }
    }

    /**
     * Delete traces older than the retention window. Public so a scheduler
     * task or CLI can call it explicitly; returns the number of deleted rows.
     */
    public function prune(?Connection $connection = null): int
    {
        $retentionDays = $this->retentionDays();
        if ($retentionDays <= 0) {
            return 0;
        }

        $connection ??= $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'crdate',
                    $queryBuilder->createNamedParameter(time() - $retentionDays * 86400, Connection::PARAM_INT),
                ),
            )
            ->executeStatement();
    }

    private function retentionDays(): int
    {
        if ($this->extensionConfiguration === null) {
            return 0;
        }

        try {
            $value = $this->extensionConfiguration->get('abilities', 'traceRetentionDays');
        } catch (\Throwable) {
            return 0;
        }

        return is_numeric($value) ? max(0, (int)$value) : 0;
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
