<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every `$ref` in a projected document points at something the document defines.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(OperationProjector::class)]
final class ReferencesResolveTest extends TestCase
{
    #[Test]
    public function everyReferenceInTheContractWitnessResolves(): void
    {
        $this->assertEveryReferenceResolves(ContractWitnessServer::build());
    }

    #[Test]
    public function typesTheServerDoesNotRegisterAreDescribedInline(): void
    {
        $albums = FakeTypeMetadata::resource(
            'albums',
            [Id::make()->build(), Str::make('title')->build()],
            operations: [OperationType::FetchOne, OperationType::Update],
            actions: [
                new FakeActionMetadata('remaster', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'remaster-orders', responds: [new ActionResource('remasters'), new Accepted('remaster-jobs')]),
                new FakeActionMetadata('retitle', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'albums', outputType: 'albums'),
            ],
            responses: [OperationType::Update->value => [new Ok(), new Accepted('album-jobs')]],
        );

        $document = $this->assertEveryReferenceResolves(new FakeServerMetadata('Music', '1', [$albums]));

        $paths = $document['paths'];
        self::assertIsArray($paths);
        $accepted = $this->at($paths, '/albums/{id}', 'patch', 'responses', '202', 'content', 'application/vnd.api+json', 'schema');
        self::assertIsArray($accepted);
        self::assertSame('album-jobs', $this->at($accepted, 'properties', 'data', 'properties', 'type', 'const'));

        // albums allows no create, so its action input falls back to the resource object.
        self::assertSame(
            ['$ref' => '#/components/schemas/AlbumsResource'],
            $this->at($paths, '/albums/{id}/-actions/retitle', 'post', 'requestBody', 'content', 'application/vnd.api+json', 'schema'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assertEveryReferenceResolves(ServerMetadataInterface $metadata): array
    {
        $document = (new OpenApiProjector())->project($metadata)->toArray();

        $refs = [];
        \array_walk_recursive($document, static function (mixed $value, int|string $key) use (&$refs): void {
            if ($key === '$ref' && \is_string($value)) {
                $refs[$value] = true;
            }
        });
        self::assertNotSame([], $refs);

        foreach (\array_keys($refs) as $ref) {
            self::assertStringStartsWith('#/', $ref);
            $cursor = $document;
            foreach (\explode('/', \substr($ref, 2)) as $segment) {
                $segment = \str_replace(['~1', '~0'], ['/', '~'], $segment);
                self::assertIsArray($cursor, $ref);
                self::assertArrayHasKey($segment, $cursor, 'Unresolved $ref ' . $ref);
                $cursor = $cursor[$segment];
            }
        }

        return $document;
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
