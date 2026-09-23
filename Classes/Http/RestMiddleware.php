<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

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
final readonly class RestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RestConfiguration $configuration,
        private RestRouter $router,
        private RestAuthenticator $authenticator,
        private RestRequestHandler $handler,
        private RestResponseFactory $responses,
        private ?LoggerInterface $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!$this->configuration->enabled || !$this->router->isApiRequest($path, $this->configuration->basePath)) {
            return $handler->handle($request);
        }

        $cors = CorsPolicy::fromString($this->configuration->corsOrigins);
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $cors->preflight($request, $this->responses->empty());
        }

        return $cors->apply($request, $this->dispatch($request, $path));
    }

    private function dispatch(ServerRequestInterface $request, string $path): ResponseInterface
    {
        $route = $this->router->match($path, $this->configuration->basePath);
        if ($route === null) {
            return $this->responses->error(RestResponseFactory::ERROR_NOT_FOUND, 'No such endpoint.', 404);
        }

        try {
            $context = $this->authenticator->authenticate($request);
            if ($context === null) {
                return $this->responses->error(
                    RestResponseFactory::ERROR_UNAUTHORIZED,
                    'Authentication required: send "Authorization: Bearer <token>" (see abilities:token:create) or use a same-origin backend session with X-Requested-With.',
                    401,
                    ['WWW-Authenticate' => 'Bearer realm="abilities"'],
                );
            }

            return $this->handler->handle($route, $request, $context);
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
