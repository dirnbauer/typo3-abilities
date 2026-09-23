<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\Abilities\Http\InvalidRestInputException;
use Webconsulting\Abilities\Http\RestInputMapper;

final class RestInputMapperTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
            'ratio' => ['type' => 'number'],
            'force' => ['type' => 'boolean'],
            'tags' => ['type' => 'array'],
            'meta' => ['type' => 'object'],
            'maybe' => ['type' => ['integer', 'null']],
            'title' => ['type' => 'string'],
        ],
    ];

    private function request(string $method, string $uri, ?string $body = null): ServerRequest
    {
        $request = new ServerRequest($uri, $method);
        if ($body !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
            $request = $request->withBody($stream)->withHeader('Content-Type', 'application/json');
        }
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);

        return $request->withQueryParams($query);
    }

    #[Test]
    public function coercesPlainQueryParametersByDeclaredType(): void
    {
        $input = new RestInputMapper()->fromRequest(
            $this->request('GET', 'http://localhost/run?id=42&ratio=0.5&force=true&tags=a,b&meta={"k":1}&maybe=&title=7&unknown=x'),
            self::SCHEMA,
        );

        self::assertSame(
            ['id' => 42, 'ratio' => 0.5, 'force' => true, 'tags' => ['a', 'b'], 'meta' => ['k' => 1], 'maybe' => null, 'title' => '7'],
            $input,
        );
    }

    #[Test]
    public function leavesUncoercibleStringsForTheSchemaValidator(): void
    {
        $input = new RestInputMapper()->fromQuery(['id' => 'abc', 'force' => 'maybe'], self::SCHEMA);

        self::assertSame(['id' => 'abc', 'force' => 'maybe'], $input);
    }

    #[Test]
    public function inputQueryParameterWinsAsJson(): void
    {
        $input = new RestInputMapper()->fromRequest(
            $this->request('GET', 'http://localhost/run?input=' . rawurlencode('{"id": 1, "title": "x"}') . '&id=9'),
            self::SCHEMA,
        );

        self::assertSame(['id' => 1, 'title' => 'x'], $input);
    }

    #[Test]
    public function jsonBodyWithOrWithoutInputWrapper(): void
    {
        $mapper = new RestInputMapper();

        self::assertSame(['id' => 1], $mapper->fromRequest($this->request('POST', 'http://localhost/run', '{"input": {"id": 1}}'), self::SCHEMA));
        self::assertSame(['id' => 2], $mapper->fromRequest($this->request('DELETE', 'http://localhost/run', '{"id": 2}'), self::SCHEMA));
        self::assertSame([], $mapper->fromRequest($this->request('POST', 'http://localhost/run', '{}'), self::SCHEMA));
        self::assertSame(['id' => 3], $mapper->fromRequest($this->request('DELETE', 'http://localhost/run?input=' . rawurlencode('{"id":3}')), self::SCHEMA), 'DELETE may use ?input=');
    }

    #[Test]
    public function rejectsMalformedBodies(): void
    {
        $mapper = new RestInputMapper();

        foreach (['not json', '[1,2]', '{"input": "x"}', '"str"'] as $body) {
            try {
                $mapper->fromRequest($this->request('POST', 'http://localhost/run', $body), self::SCHEMA);
                self::fail('expected InvalidRestInputException for ' . $body);
            } catch (InvalidRestInputException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
