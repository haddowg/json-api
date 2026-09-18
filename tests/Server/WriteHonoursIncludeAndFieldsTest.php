<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\StubResource;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The runtime half of the projection decision behind
 * {@see \haddowg\JsonApi\OpenApi\OperationProjector::documentShapeParameters()}: a write
 * that answers with a resource document honours `?include` and `fields[<type>]` exactly
 * as a read does, and a relationship-endpoint mutation — whose document is linkage —
 * honours neither. Driven end to end through the PSR-15 {@see Server::handle()} path, so
 * the strict query-parameter gate runs on the real request.
 */
#[Group('spec:inclusion-of-related-resources')]
#[Group('spec:sparse-fieldsets')]
final class WriteHonoursIncludeAndFieldsTest extends TestCase
{
    #[Test]
    public function aCreateRespondsWithTheCompoundDocumentTheRequestAskedFor(): void
    {
        $request = (new ServerRequest('POST', '/api/articles?include=author&fields[people]=name'))
            ->withQueryParams(['include' => 'author', 'fields' => ['people' => 'name']])
            ->withParsedBody(['data' => ['type' => 'articles', 'attributes' => ['title' => 'Hello']]])
            ->withAttribute(Target::class, new Target('articles'));

        $body = $this->decode($this->handle($request));

        self::assertArrayHasKey('included', $body, 'A POST asked to include a relation must answer with a compound document.');
        self::assertSame(
            [[
                'type' => 'people',
                'id' => '9',
                'links' => ['self' => '/people/9'],
                // `fields[people]=name` dropped `email` from the included resource, so the
                // sparse fieldset reached a write's response too.
                'attributes' => ['name' => 'Ada'],
            ]],
            $body['included'],
        );
    }

    #[Test]
    public function anUpdateRespondsWithTheCompoundDocumentTheRequestAskedFor(): void
    {
        $request = (new ServerRequest('PATCH', '/api/articles/1?include=author'))
            ->withQueryParams(['include' => 'author'])
            ->withParsedBody(['data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Hello']]])
            ->withAttribute(Target::class, new Target('articles', '1'));

        $body = $this->decode($this->handle($request));

        self::assertArrayHasKey('included', $body);
        self::assertSame('people', $this->firstIncludedType($body));
    }

    #[Test]
    public function aWriteWithNoIncludeStaysASimpleDocument(): void
    {
        $request = (new ServerRequest('POST', '/api/articles'))
            ->withParsedBody(['data' => ['type' => 'articles', 'attributes' => ['title' => 'Hello']]])
            ->withAttribute(Target::class, new Target('articles'));

        self::assertArrayNotHasKey('included', $this->decode($this->handle($request)));
    }

    #[Test]
    public function aRelationshipMutationNeverCarriesAnIncludedMember(): void
    {
        // A relationship endpoint's document is linkage-only: `?include` is tolerated by
        // the strict gate (it is a reserved family) but produces nothing, which is why the
        // projector advertises it on the resource writes and not on these.
        $request = (new ServerRequest('PATCH', '/api/articles/1/relationships/author?include=author'))
            ->withQueryParams(['include' => 'author'])
            ->withParsedBody(['data' => ['type' => 'people', 'id' => '9']])
            ->withAttribute(Target::class, new Target('articles', '1', 'author'));

        $body = $this->decode($this->handle($request));

        self::assertSame(['type' => 'people', 'id' => '9'], $body['data']);
        self::assertArrayNotHasKey('included', $body);
    }

    /**
     * Runs the request through a server whose handler answers each write the way a real
     * one does: a resource document for the resource-level writes, the echoed linkage for
     * the relationship mutation.
     */
    private function handle(ServerRequest $request): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        $handler = new class ($this->articles()) implements OperationHandlerInterface {
            public function __construct(private readonly SerializerInterface $articles) {}

            public function handle(JsonApiOperationInterface $operation): DataResponse|IdentifierResponse
            {
                return match (true) {
                    $operation instanceof CreateResourceOperation,
                    $operation instanceof UpdateResourceOperation => DataResponse::fromResource(new \stdClass(), $this->articles),
                    $operation instanceof UpdateRelationshipOperation => IdentifierResponse::forRelationship(new \stdClass(), $this->articles, 'author'),
                    default => throw new \LogicException('Unexpected operation ' . $operation::class),
                };
            }
        };

        return Server::make()->withPsr17($psr17, $psr17)->withHandler($handler)->handle($request);
    }

    /**
     * An `articles` serializer with an `author` to-one whose target carries two
     * attributes, so a sparse fieldset on the INCLUDED type is observable.
     */
    private function articles(): SerializerInterface
    {
        $author = new StubResource('people', '9', attributes: [
            'name' => static fn(): string => 'Ada',
            'email' => static fn(): string => 'ada@example.com',
        ]);

        return new StubResource(
            type: 'articles',
            id: '1',
            attributes: ['title' => static fn(): string => 'Hello'],
            relationships: [
                'author' => static fn(): ToOneRelationship => ToOneRelationship::create()->setData(new \stdClass(), $author),
            ],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function firstIncludedType(array $body): string
    {
        $included = $body['included'] ?? null;
        self::assertIsArray($included);
        $first = $included[0] ?? null;
        self::assertIsArray($first);
        $type = $first['type'] ?? null;
        self::assertIsString($type);

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = \json_decode($response->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
