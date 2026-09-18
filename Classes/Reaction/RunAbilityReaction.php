<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Reaction;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Http\InvalidRestInputException;
use Webconsulting\Abilities\Http\RestInputMapper;
use Webconsulting\Abilities\Http\RestResponseFactory;
use Webconsulting\Abilities\Permission\BackendUserContext;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * The webhook surface: an EXT:reactions reaction type "Run ability". The
 * reaction record picks the ability and the backend user to impersonate;
 * the incoming JSON payload is the ability input ({"input": {...}} or a
 * bare object). The run is governed exactly like REST — the impersonated
 * user's be_groups scopes, the policy, checkPermission() — and can never
 * approve a review. The response is the REST envelope.
 *
 * Registered only when typo3/cms-reactions is installed (see Configuration/Services.php).
 */
final class RunAbilityReaction implements ReactionInterface
{
    public const TYPE = 'abilities-run';
    public const FIELD_ABILITY = 'tx_abilities_ability';

    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
        private readonly BackendUserScopeResolver $scopeResolver,
        private readonly RestInputMapper $inputMapper,
        private readonly RestResponseFactory $responses,
    ) {}

    public static function getType(): string
    {
        return self::TYPE;
    }

    public static function getDescription(): string
    {
        return 'LLL:EXT:abilities/Resources/Private/Language/locallang_db.xlf:sys_reaction.reaction_type.abilities_run';
    }

    public static function getIconIdentifier(): string
    {
        return 'abilities-module';
    }

    /**
     * @param array<mixed> $payload
     */
    public function react(ServerRequestInterface $request, array $payload, ReactionInstruction $reaction): ResponseInterface
    {
        $name = $reaction->toArray()[self::FIELD_ABILITY] ?? '';
        $name = is_string($name) ? $name : '';
        if (!$this->registry->has($name) || !$this->registry->getDefinition($name)->isExposedTo(ExecutionContext::SURFACE_REST)) {
            return $this->responses->error(
                RestResponseFactory::ERROR_ABILITY_NOT_FOUND,
                sprintf('Reaction "%s" is not bound to a REST-exposed ability.', $reaction->getName()),
                404,
            );
        }

        try {
            $input = $this->inputMapper->fromPayload($payload);
        } catch (InvalidRestInputException $exception) {
            return $this->responses->error(AbilityErrorCode::InvalidInput->value, $exception->getMessage(), 400);
        }

        $user = BackendUserContext::current();
        $result = $this->executor->execute(
            $this->registry->get($name),
            $input,
            ExecutionContext::webhook(
                $user === null ? [] : $this->scopeResolver->resolveForUser($user),
                BackendUserContext::currentUid(),
            ),
            $this->registry->getDefinition($name),
        );

        return $this->responses->fromResult($result);
    }
}
