<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability\Workspace;

use Psr\Container\ContainerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Permission\BackendUserContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Demo ability: human-in-the-loop publishing. dryRun (the default) lists
 * what would go live; the real run publishes through the same command map
 * the workspaces module uses. Idempotent: publishing an already published
 * workspace is a no-op. Every attempt — dry runs, review denials, publishes
 * — leaves a tx_abilities_trace row.
 *
 * EXT:workspaces is optional for this extension: the ability is registered
 * regardless (the registry lists it), but denies with a clear message when
 * the workspaces extension is not installed.
 */
#[AsAbility(
    name: 'workspace/publish',
    title: 'Publish workspace',
    description: 'Lists the records pending in a workspace (dry run, the default) or publishes all of them to live — the same operation as the "Publish" button of the Workspaces module.',
    category: 'workspace',
    scopes: ['workspace:publish'],
    riskTier: RiskTier::High,
    sideEffects: ['database:write'],
    idempotent: true,
    instructions: 'Run with dryRun=true first and show the human the pending records; only then run again with dryRun=false. Publishing an empty (already published) workspace is a no-op, so retries are safe. Requires EXT:workspaces and publish access to the workspace.',
)]
final class PublishWorkspaceAbility extends AbstractAbility
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace'],
            'additionalProperties' => false,
            'properties' => [
                'workspace' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Uid of the sys_workspace record',
                ],
                'dryRun' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'true: only list the pending records; false: publish them',
                ],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['workspace', 'dryRun', 'pending', 'published', 'records'],
            'properties' => [
                'workspace' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'dryRun' => ['type' => 'boolean'],
                'pending' => ['type' => 'integer', 'description' => 'Records that were pending before this run'],
                'published' => ['type' => 'integer', 'description' => 'Records published by this run (0 on dry run)'],
                'records' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['table', 'uid', 'liveUid', 'pid', 'title', 'stage'],
                        'properties' => [
                            'table' => ['type' => 'string'],
                            'uid' => ['type' => 'integer', 'description' => 'Uid of the workspace version'],
                            'liveUid' => ['type' => 'integer', 'description' => 'Uid of the live record (equals uid for new records)'],
                            'pid' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'stage' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $user = BackendUserContext::current();
        if ($user === null) {
            return BackendUserContext::missingUserMessage('workspace/publish');
        }
        if (!$this->workspacesAvailable()) {
            return 'EXT:workspaces is not installed; workspace/publish has nothing to publish.';
        }

        $workspace = is_int($input['workspace'] ?? null) ? $input['workspace'] : 0;
        if ($workspace > 0 && !$this->mayPublish($user, $workspace)) {
            return sprintf('Backend user has no publish access to workspace #%d.', $workspace);
        }

        return true;
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $user = BackendUserContext::current()
            ?? throw new \RuntimeException(BackendUserContext::missingUserMessage('workspace/publish'), 7480291060);
        if (!$this->workspacesAvailable()) {
            throw new \RuntimeException('EXT:workspaces is not installed.', 7480291061);
        }

        $workspace = is_int($input['workspace'] ?? null) ? $input['workspace'] : 0;
        $dryRun = ($input['dryRun'] ?? true) !== false;

        $workspaceRecord = BackendUtility::getRecord('sys_workspace', $workspace, ['uid', 'title', 'publish_access']);
        if ($workspaceRecord === null) {
            throw new \RuntimeException(sprintf('Workspace #%d does not exist.', $workspace), 7480291062);
        }

        $service = $this->container->get(WorkspaceService::class);
        if (!$service instanceof WorkspaceService) {
            throw new \RuntimeException('WorkspaceService is not available.', 7480291063);
        }

        // Mirror getCmdArrayForPublishWS(): a workspace may restrict publishing
        // to records in the "ready to publish" stage.
        $stage = ((int)($workspaceRecord['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE)
            ? StagesService::STAGE_PUBLISH_ID
            : -99;
        $records = [];
        foreach ($service->selectVersionsInWorkspace($workspace, $stage, -1, 999, 'tables_modify') as $table => $rows) {
            foreach ($rows as $row) {
                $versionUid = (int)($row['uid'] ?? 0);
                $liveUid = (int)($row['t3ver_oid'] ?? 0) ?: $versionUid;
                $versionRecord = BackendUtility::getRecord((string)$table, $versionUid);
                $records[] = [
                    'table' => (string)$table,
                    'uid' => $versionUid,
                    'liveUid' => $liveUid,
                    'pid' => (int)($row['livepid'] ?? $row['pid'] ?? 0),
                    'title' => $versionRecord === null ? '' : BackendUtility::getRecordTitle((string)$table, $versionRecord),
                    'stage' => (int)($row['t3ver_stage'] ?? 0),
                ];
            }
        }

        $published = 0;
        if (!$dryRun && $records !== []) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $service->getCmdArrayForPublishWS($workspace), $user);
            $dataHandler->process_cmdmap();
            if ($dataHandler->errorLog !== []) {
                throw new \RuntimeException('DataHandler: ' . implode('; ', $dataHandler->errorLog), 7480291064);
            }
            $published = count($records);
        }

        return [
            'workspace' => $workspace,
            'title' => (string)($workspaceRecord['title'] ?? ''),
            'dryRun' => $dryRun,
            'pending' => count($records),
            'published' => $published,
            'records' => $records,
        ];
    }

    /**
     * Publish access exactly as the workspaces module grants it: admins and
     * workspace owners always, members only when the workspace does not
     * restrict publishing to owners and they may edit live.
     */
    private function mayPublish(BackendUserAuthentication $user, int $workspace): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $access = $user->checkWorkspace($workspace);
        if ($access === false) {
            return false;
        }
        if (($access['_ACCESS'] ?? '') === 'owner') {
            return true;
        }

        return $user->checkWorkspace(WorkspaceService::LIVE_WORKSPACE_ID) !== false
            && ((int)($access['publish_access'] ?? 0) & WorkspaceService::PUBLISH_ACCESS_ONLY_WORKSPACE_OWNERS) === 0;
    }

    private function workspacesAvailable(): bool
    {
        return class_exists(WorkspaceService::class) && $this->container->has(WorkspaceService::class);
    }
}
