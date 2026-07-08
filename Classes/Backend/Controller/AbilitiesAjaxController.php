<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * Backend AJAX endpoints for the abilities module. Both run inside the
 * authenticated backend (session-guarded, access inherited from the module),
 * so they carry no token: the acting backend user is the identity, and the
 * executor runs on the trusted "backend" surface (scope checks skipped;
 * policy and each ability's checkPermission() still govern).
 */
final class AbilitiesAjaxController
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
    ) {
    }

    public function describe(ServerRequestInterface $request): ResponseInterface
    {
        $nameParam = $request->getQueryParams()['name'] ?? null;
        $name = is_string($nameParam) ? $nameParam : '';
        if (!$this->registry->has($name)) {
            return new JsonResponse(['error' => 'Unknown ability.'], 404);
        }

        $ability = $this->registry->get($name);

        return new JsonResponse([
            ...$this->registry->getDefinition($name)->toArray(),
            'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
            'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
        ]);
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        if (!$this->registry->has($name)) {
            return new JsonResponse(['ok' => false, 'errorCode' => 'not_found', 'error' => 'Unknown ability.'], 404);
        }

        $input = $body['input'] ?? [];
        if (!is_array($input)) {
            return new JsonResponse(['ok' => false, 'errorCode' => 'invalid_input', 'error' => 'input must be an object.'], 400);
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
            ),
        );

        return new JsonResponse($result->toArray(), $this->httpStatusFor($result));
    }

    private function httpStatusFor(AbilityResult $result): int
    {
        if ($result->ok) {
            return 200;
        }

        return match ($result->errorCode) {
            AbilityResult::ERROR_INVALID_INPUT => 400,
            AbilityResult::ERROR_POLICY_DENIED, AbilityResult::ERROR_PERMISSION_DENIED => 403,
            default => 500,
        };
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
