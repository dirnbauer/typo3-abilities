<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * PSR-15 entry point of the REST projection, mounted in the frontend
 * middleware stack before site resolution (the API needs no site): answers
 * every request below the configured base path (default /abilities/v1) and
 * passes everything else through untouched.
 *
 * Responsibilities, in order: feature flag → CORS preflight → routing →
 * authentication (bearer token or same-origin backend session) → handler.
 * Unexpected exceptions become 500 rest_ability_cannot_execute; the
 * details go to the log, never to the client.
 */
final class RestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RestRouter $router,
        private readonly RestAuthenticator $authenticator,
        private readonly RestRequestHandler $handler,
        private readonly RestResponseFactory $responses,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $configuration = RestConfiguration::fromExtensionConfiguration($this->extensionConfiguration);
        $path = $request->getUri()->getPath();
        if (!$configuration->enabled || !$this->router->isApiRequest($path, $configuration->basePath)) {
            return $handler->handle($request);
        }

        $cors = CorsPolicy::fromString($configuration->corsOrigins);
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $cors->preflight($request, $this->responses->empty());
        }

        return $cors->apply($request, $this->dispatch($request, $path, $configuration));
    }

    private function dispatch(ServerRequestInterface $request, string $path, RestConfiguration $configuration): ResponseInterface
    {
        $route = $this->router->match($path, $configuration->basePath);
        if ($route === null) {
            return $this->responses->error(RestResponseFactory::ERROR_NOT_FOUND, 'No such endpoint.', 404);
        }

        try {
            $identity = $this->authenticator->authenticate($request);
            if ($identity === null) {
                return $this->responses->error(
                    RestResponseFactory::ERROR_UNAUTHORIZED,
                    'Authentication required: send "Authorization: Bearer <token>" (see abilities:token:create) or use a same-origin backend session with X-Requested-With.',
                    401,
                    ['WWW-Authenticate' => 'Bearer realm="abilities"'],
                );
            }

            return $this->handler->handle($route, $request, $identity);
        } catch (\Throwable $exception) {
            $this->logger?->error('Abilities REST request failed: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'path' => $path,
            ]);

            return $this->responses->error(
                RestResponseFactory::ERROR_ABILITY_CANNOT_EXECUTE,
                'The request could not be processed.',
                500,
            );
        }
    }
}
