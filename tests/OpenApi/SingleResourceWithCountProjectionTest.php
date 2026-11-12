<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationFacts;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An operation rendering one resource takes `withCount` for that resource's countable
 * relations: a fetch-one, a create or update answering with the resource, a to-one related
 * read and an action answering with a resource. `_self_` names a collection's total, so none
 * of them takes it.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OperationFacts::class)]
final class SingleResourceWithCountProjectionTest extends TestCase
{
    #[Test]
    public function everyOperationRenderingOneArticleTakesItsCountableRelations(): void
    {
        $document = $this->project();

        foreach ([['/articles/{id}', 'get'], ['/articles', 'post'], ['/articles/{id}', 'patch'], ['/comments/{id}/article', 'get'], ['/comments/{id}/-actions/promote', 'post']] as [$path, $method]) {
            $parameter = $this->withCount($document, $path, $method);
            self::assertNotNull($parameter, "{$method} {$path}");
            self::assertSame(['comments'], $this->at($parameter, 'schema', 'items', 'enum'), "{$method} {$path}");
            self::assertSame(CountableProfile::URI, $parameter['x-profile'] ?? null);
            self::assertContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, $path, $method), "{$method} {$path}");
        }
    }

    #[Test]
    public function anOperationRenderingNoResourceOrNothingCountableTakesNone(): void
    {
        $document = $this->project();

        self::assertNull($this->withCount($document, '/articles/{id}', 'delete'));
        self::assertNull($this->withCount($document, '/comments/{id}', 'get'), 'nothing on a comment counts');
        self::assertNull($this->withCount($document, '/comments/{id}', 'patch'), 'the update answers 204');
        self::assertNull($this->withCount($document, '/articles/{id}/-actions/archive', 'post'), 'the action answers 204');
        self::assertNull($this->withCount($document, '/comments/{id}/relationships/article', 'get'), 'a to-one linkage renders no resource');
        self::assertNotContains('RELATIONSHIP_COUNT_NOT_ALLOWED', $this->codes($document, '/comments/{id}', 'get'));
    }

    #[Test]
    public function withoutTheCountableProfileNothingTakesWithCount(): void
    {
        $document = $this->project(profiles: []);

        self::assertNull($this->withCount($document, '/articles/{id}', 'get'));
        self::assertNull($this->withCount($document, '/articles', 'post'));
    }

    /**
     * @param list<string> $profiles
     *
     * @return array<array-key, mixed>
     */
    private function project(array $profiles = [CountableProfile::URI]): array
    {
        $server = new FakeServerMetadata(
            title: 'API',
            version: '1.0.0',
            types: [
                FakeTypeMetadata::resource(
                    type: 'articles',
                    fields: [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()],
                    relations: [new FakeRelationMetadata('comments', ['comments'], true, countable: true)],
                    countable: true,
                    actions: [new FakeActionMetadata('archive', ['POST'])],
                ),
                FakeTypeMetadata::resource(
                    type: 'comments',
                    fields: [Id::make()->example('1')->build(), Str::make('body')->example('Nice')->build()],
                    relations: [FakeRelationMetadata::toOne('article', ['articles'])],
                    responses: [OperationType::Update->value => [new NoContent()]],
                    actions: [new FakeActionMetadata('promote', ['POST'], outputType: 'articles')],
                ),
            ],
            profiles: $profiles,
        );

        return $this->projectServer($server);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function projectServer(ServerMetadataInterface $server): array
    {
        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>|null
     */
    private function withCount(array $document, string $path, string $method): ?array
    {
        $operation = $this->at($document, 'paths', $path, $method);
        self::assertIsArray($operation);
        foreach ((array) ($operation['parameters'] ?? []) as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'withCount') {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Every code an operation's error responses offer.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function codes(array $document, string $path, string $method): array
    {
        $codes = [];
        $responses = $this->at($document, 'paths', $path, $method, 'responses');
        self::assertIsArray($responses);
        foreach ($responses as $status => $response) {
            if ((int) $status < 400 || !\is_array($response)) {
                continue;
            }
            $ref = $response['$ref'] ?? null;
            if (\is_string($ref)) {
                $response = $this->at($document, 'components', 'responses', \substr($ref, \strlen('#/components/responses/')));
                self::assertIsArray($response);
            }
            $schema = $this->at($response, 'content', 'application/vnd.api+json', 'schema', '$ref');
            self::assertIsString($schema);
            $branches = $this->at($document, 'components', 'schemas', \substr($schema, \strlen('#/components/schemas/')), 'properties', 'errors', 'items', 'anyOf');
            self::assertIsArray($branches);
            foreach ($branches as $branch) {
                self::assertIsArray($branch);
                self::assertIsString($branch['$ref'] ?? null);
                $shape = $this->at($document, 'components', 'schemas', \substr($branch['$ref'], \strlen('#/components/schemas/')));
                self::assertIsArray($shape);
                $allOf = $shape['allOf'] ?? null;
                $narrowing = \is_array($allOf) ? ($allOf[1] ?? null) : null;
                if (!\is_array($narrowing)) {
                    continue;
                }
                foreach ((array) $this->at($narrowing, 'properties', 'code', 'anyOf') as $code) {
                    self::assertIsArray($code);
                    self::assertIsString($code['const'] ?? null);
                    $codes[] = $code['const'];
                }
            }
        }

        return $codes;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, string ...$keys): mixed
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }
}
