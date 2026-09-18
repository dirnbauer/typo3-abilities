<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability\Content;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Permission\BackendUserContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Demo ability: a governed write. The page is created hidden through the
 * DataHandler as the acting backend user — so TYPO3's page permissions,
 * slug generation and the user's current workspace (t3ver_*) all apply
 * exactly as they would for a human editor. Before/After events fire around
 * it like around every ability (see the functional test with a listener).
 */
#[AsAbility(
    name: 'content/create-page-draft',
    title: 'Create page draft',
    description: 'Creates a hidden page below a parent page through the DataHandler, in the workspace of the acting backend user, and returns its uid and slug.',
    category: 'content',
    scopes: ['pages:write'],
    riskTier: RiskTier::Medium,
    sideEffects: ['database:write'],
    idempotent: false,
    instructions: 'Pass "parent" (uid of the page the draft goes under; 0 = root level, admins only) and a "title". The page is always created hidden — an editor unhides it, or a workspace publishes it. Optional "slug" (URL path segment, generated from the title when omitted) and "doktype" (1 = standard page, 4 = shortcut, 254 = folder).',
)]
final class CreatePageDraftAbility extends AbstractAbility
{
    private const NEW_ID = 'NEW_abilities_page_draft';

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['parent', 'title'],
            'additionalProperties' => false,
            'properties' => [
                'parent' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Uid of the parent page (0 = root level)',
                ],
                'title' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'description' => 'Page title',
                ],
                'slug' => [
                    'type' => 'string',
                    'maxLength' => 2048,
                    'default' => '',
                    'description' => 'URL path segment; generated from the title when empty',
                ],
                'doktype' => [
                    'type' => 'integer',
                    'enum' => [1, 3, 4, 6, 7, 199, 254],
                    'default' => 1,
                    'description' => 'Page type: 1 standard, 3 external link, 4 shortcut, 6 backend user section, 7 mount point, 199 spacer, 254 folder',
                ],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['uid', 'pid', 'slug', 'hidden', 'workspace'],
            'properties' => [
                'uid' => ['type' => 'integer'],
                'pid' => ['type' => 'integer'],
                'slug' => ['type' => 'string'],
                'hidden' => ['type' => 'boolean'],
                'workspace' => ['type' => 'integer'],
            ],
        ];
    }

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $user = BackendUserContext::current();
        if ($user === null) {
            return BackendUserContext::missingUserMessage('content/create-page-draft');
        }
        if ($user->isAdmin()) {
            return true;
        }

        $parent = is_int($input['parent'] ?? null) ? $input['parent'] : -1;
        if ($parent === 0) {
            return 'Only administrators may create pages on the root level.';
        }
        $parentRecord = $parent > 0 ? BackendUtility::getRecord('pages', $parent) : null;
        if ($parentRecord === null) {
            return sprintf('Parent page #%d does not exist.', $parent);
        }
        if (!$user->doesUserHaveAccess($parentRecord, Permission::PAGE_NEW)) {
            return sprintf('Backend user may not create pages below page #%d.', $parent);
        }

        return true;
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $user = BackendUserContext::current()
            ?? throw new \RuntimeException(BackendUserContext::missingUserMessage('content/create-page-draft'), 7480291040);

        $parent = is_int($input['parent'] ?? null) ? $input['parent'] : 0;
        if ($parent > 0 && BackendUtility::getRecord('pages', $parent) === null) {
            throw new \RuntimeException(sprintf('Parent page #%d does not exist.', $parent), 7480291041);
        }

        $fields = [
            'pid' => $parent,
            'title' => is_string($input['title'] ?? null) ? $input['title'] : '',
            'hidden' => 1,
            'doktype' => is_int($input['doktype'] ?? null) ? $input['doktype'] : 1,
        ];
        $slug = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
        if ($slug !== '') {
            $fields['slug'] = $slug;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [self::NEW_ID => $fields]], [], $user);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException('DataHandler: ' . implode('; ', $dataHandler->errorLog), 7480291042);
        }
        $uid = (int)($dataHandler->substNEWwithIDs[self::NEW_ID] ?? 0);
        $record = $uid > 0 ? BackendUtility::getRecord('pages', $uid, ['uid', 'pid', 'slug', 'hidden', 't3ver_wsid']) : null;
        if ($record === null) {
            throw new \RuntimeException('The DataHandler did not create the page.', 7480291043);
        }

        return [
            'uid' => (int)$record['uid'],
            'pid' => (int)$record['pid'],
            'slug' => (string)$record['slug'],
            'hidden' => (bool)$record['hidden'],
            'workspace' => (int)($record['t3ver_wsid'] ?? 0),
        ];
    }
}
