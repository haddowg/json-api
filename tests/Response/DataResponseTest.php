<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class DataResponseTest extends TestCase
{
    #[Test]
    public function fromResourceRendersSingleResourceAsData(): void
    {
        $resource = new StubResource('user', '1', attributes: ['name' => static fn(): string => 'Vader']);

        $response = DataResponse::fromResource(new \stdClass(), $resource);

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(200, $psr->getStatusCode());
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame(
            [
                'data' => [
                    'type' => 'user',
                    'id' => '1',
                    'links' => ['self' => '/user/1'],
                    'attributes' => ['name' => 'Vader'],
                ],
                'links' => ['self' => '/'],
                'jsonapi' => ['version' => '1.1'],
            ],
            $this->decode($psr->getBody()->getContents()),
        );
    }

    #[Test]
    public function fromCollectionRendersListAsData(): void
    {
        $resource = new StubResource('user', '1');

        $response = DataResponse::fromCollection([new \stdClass()], $resource);

        $psr = $response->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(200, $psr->getStatusCode());
        self::assertArrayHasKey('data', $body);
        self::assertSame([['type' => 'user', 'id' => '1', 'links' => ['self' => '/user/1']]], $body['data']);
        self::assertSame(['self' => '/'], $body['links']);
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    #[Group('spec:sparse-fieldsets')]
    public function includeAndSparseFieldsetsFlowThroughToOutput(): void
    {
        $resource = new StubResource(
            type: 'user',
            id: '1',
            attributes: [
                'name' => static fn(): string => 'Vader',
                'secret' => static fn(): string => 'hidden',
            ],
            relationships: [
                'father' => static fn(): ToOneRelationship => ToOneRelationship::create()
                    ->setData([''], new StubResource('user', '2', attributes: ['name' => static fn(): string => 'Grandfather'])),
            ],
        );

        $request = StubJsonApiRequest::create([
            'include' => 'father',
            'fields' => ['user' => 'name,father'],
        ]);

        $psr = DataResponse::fromResource(new \stdClass(), $resource)
            ->toPsrResponse(new StubServer(), $request);

        $body = $this->decode($psr->getBody()->getContents());

        // Sparse fieldset hides "secret"; "father" relationship retained and included.
        self::assertSame(
            [
                'type' => 'user',
                'id' => '1',
                'links' => ['self' => '/user/1'],
                'attributes' => ['name' => 'Vader'],
                'relationships' => [
                    'father' => ['data' => ['type' => 'user', 'id' => '2']],
                ],
            ],
            $body['data'],
        );
        self::assertSame(
            [[
                'type' => 'user',
                'id' => '2',
                'links' => ['self' => '/user/2'],
                'attributes' => ['name' => 'Grandfather'],
            ]],
            $body['included'],
        );
    }

    #[Test]
    public function withersReturnNewInstanceAndLeaveOriginalUnchanged(): void
    {
        $resource = new StubResource('user', '1');
        $base = DataResponse::fromResource(new \stdClass(), $resource);

        $links = new DocumentLinks(links: ['self' => new Link('/users/1')]);
        $jsonApi = new JsonApiObject('1.1', ['impl' => 'haddowg']);

        $withMeta = $base->withMeta(['page' => 1]);
        $withLinks = $base->withLinks($links);
        $withJsonApi = $base->withJsonApi($jsonApi);
        $withHeader = $base->withHeader('X-Test', 'yes');

        self::assertNotSame($base, $withMeta);
        self::assertNotSame($base, $withLinks);
        self::assertNotSame($base, $withJsonApi);
        self::assertNotSame($base, $withHeader);

        // Original is unchanged: no meta, no hand-set links (only the by-convention
        // top-level self the document always carries), and default jsonapi.
        $original = $this->decode(
            $base->toPsrResponse(new StubServer(), StubJsonApiRequest::create())->getBody()->getContents(),
        );
        self::assertArrayNotHasKey('meta', $original);
        self::assertSame(['self' => '/'], $original['links']);
        self::assertSame(['version' => '1.1'], $original['jsonapi']);
    }

    #[Test]
    public function metaLinksJsonApiAndHeadersAppearInOutput(): void
    {
        $resource = new StubResource('user', '1');

        $psr = DataResponse::fromResource(new \stdClass(), $resource)
            ->withMeta(['page' => 1])
            ->withLinks(new DocumentLinks(links: ['self' => new Link('/users/1')]))
            ->withJsonApi(new JsonApiObject('1.1', ['impl' => 'haddowg']))
            ->withHeader('X-Test', 'yes')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        // Top-level members are always serialized in the canonical order: data
        // first, jsonapi last (TopLevelMembers::ORDER), regardless of the order they
        // were set on the response.
        self::assertSame(['data', 'links', 'meta', 'jsonapi'], \array_keys($body));
        self::assertSame('yes', $psr->getHeaderLine('X-Test'));
        self::assertSame(['page' => 1], $body['meta']);
        self::assertSame(['self' => '/users/1'], $body['links']);
        self::assertSame(['version' => '1.1', 'meta' => ['impl' => 'haddowg']], $body['jsonapi']);
    }

    #[Test]
    public function jsonApiObjectFallsBackToServerDefaults(): void
    {
        $resource = new StubResource('user', '1');
        $server = new StubServer(jsonApiVersion: '1.1', defaultMeta: ['by' => 'server']);

        $psr = DataResponse::fromResource(new \stdClass(), $resource)
            ->toPsrResponse($server, StubJsonApiRequest::create());

        $body = $this->decode($psr->getBody()->getContents());

        self::assertSame(['version' => '1.1', 'meta' => ['by' => 'server']], $body['jsonapi']);
    }

    #[Test]
    public function withStatusOverridesTheRenderedStatusAndCarriesALocationHeader(): void
    {
        $resource = new StubResource('user', '1', attributes: ['name' => static fn(): string => 'Vader']);

        // The create case: a 201 with a Location header, body still rendered.
        $psr = DataResponse::fromResource(new \stdClass(), $resource)
            ->withStatus(201)
            ->withHeader('Location', '/users/1')
            ->toPsrResponse(new StubServer(), StubJsonApiRequest::create());

        self::assertSame(201, $psr->getStatusCode());
        self::assertSame('/users/1', $psr->getHeaderLine('Location'));
        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('data', $this->decode($psr->getBody()->getContents()));
    }

    #[Test]
    public function plainServerRequestIsWrappedAsJsonApiRequest(): void
    {
        $resource = new StubResource('user', '1');

        // A plain PSR-7 ServerRequest (not a JsonApiRequestInterface) is wrapped internally.
        $plainRequest = new \Nyholm\Psr7\ServerRequest('GET', '/users/1');

        $psr = DataResponse::fromResource(new \stdClass(), $resource)
            ->toPsrResponse(new StubServer(), $plainRequest);

        self::assertSame(200, $psr->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
