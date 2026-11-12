<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\ExampleDocumentBuilder;
use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApi\OpenApi\LinkSchemaProjector;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A related-only type ({@see \haddowg\JsonApi\OpenApi\Metadata\DeclaresRelatedOnly}) has
 * no paths, and neither its schema nor its examples describe a `self` link.
 */
#[CoversClass(OpenApiProjector::class)]
#[CoversClass(LinkSchemaProjector::class)]
#[CoversClass(ExampleDocumentBuilder::class)]
#[CoversClass(GeneratorContract::class)]
final class RelatedOnlyProjectionTest extends TestCase
{
    #[Test]
    public function aRelatedOnlyTypeHasNoPathsOfItsOwn(): void
    {
        $paths = \array_keys(self::arr($this->project()['paths']));

        self::assertSame([], \array_values(\array_filter($paths, static fn(string $path): bool => \str_starts_with($path, '/labels'))));
        self::assertContains('/albums/{id}/label', $paths);
        self::assertContains('/albums/{id}/-actions/relabel', $paths);
    }

    #[Test]
    public function itsResourceLinksListNoSelf(): void
    {
        $schemas = self::arr(self::at($this->project(), 'components', 'schemas'));

        self::assertSame('#/components/schemas/LabelsResourceLinks', self::at($schemas, 'LabelsResource', 'properties', 'links', '$ref'));
        $links = self::arr($schemas['LabelsResourceLinks']);
        self::assertSame('object', $links['type']);
        self::assertArrayNotHasKey('allOf', $links);
        self::assertArrayNotHasKey('properties', $links);
        self::assertSame(['$ref' => '#/components/schemas/LinkObject'], $links['additionalProperties']);

        // Every other type keeps the shared links, `self` included.
        self::assertSame('#/components/schemas/ResourceLinks', self::at($schemas, 'AlbumsResource', 'properties', 'links', '$ref'));
        self::assertArrayHasKey('self', self::arr(self::at($schemas, 'ResourceLinks', 'properties')));
    }

    #[Test]
    public function anActionCanTakeItAsItsRequestDocument(): void
    {
        $schemas = self::arr(self::at($this->project(), 'components', 'schemas'));

        self::assertArrayHasKey('LabelsCreateRequest', $schemas);
        self::assertArrayNotHasKey('LabelsUpdateRequest', $schemas);
    }

    #[Test]
    public function itsExamplesCarryNoSelfOrRelationshipLinks(): void
    {
        $label = self::arr(self::at($this->project(), 'paths', '/albums/{id}/label', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data'));

        self::assertSame('labels', $label['type']);
        self::assertArrayNotHasKey('links', $label);
        self::assertSame(['data' => ['type' => 'labels', 'id' => 'lab-1']], self::at($label, 'relationships', 'parent'));
    }

    #[Test]
    public function theLinkObjectExampleIsAResourceThatHasASelfLink(): void
    {
        $examples = self::at($this->project(), 'components', 'schemas', 'LinkObject', 'examples');

        self::assertSame([['href' => 'https://api.example.com/albums/alb-1']], $examples);
    }

    #[Test]
    public function aDocumentWithARelatedOnlyTypeCarriesItsContract(): void
    {
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::RELATED_ONLY)], self::at($this->project(), 'info', 'x-generator'));
        self::assertSame(['contract' => GeneratorContract::BASELINE], self::at($this->project(relatedOnly: false), 'info', 'x-generator'));
    }

    #[Test]
    public function aRelatedOnlyTypeWithAnOperationIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Type "labels" is related-only, so it serves no endpoint of its own, but its metadata exposes operations.');

        $this->project(labelOperations: [OperationType::FetchOne]);
    }

    #[Test]
    public function aRelatedOnlyTypeWithAnActionIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its metadata exposes actions.');

        $this->project(labelActions: [new FakeActionMetadata('archive')]);
    }

    #[Test]
    public function aRelatedOnlyTypeWithARelationshipEndpointIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('its metadata exposes an endpoint for its "parent" relation.');

        $this->project(parentRelationshipEndpoint: true);
    }

    /**
     * @param list<OperationType>                                                       $labelOperations
     * @param list<\haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface>            $labelActions
     *
     * @return array<string, mixed>
     */
    private function project(bool $relatedOnly = true, array $labelOperations = [], array $labelActions = [], bool $parentRelationshipEndpoint = false): array
    {
        $labels = FakeTypeMetadata::resource(
            type: 'labels',
            fields: [Id::make()->example('lab-1')->build(), Str::make('name')->example('Island')->build()],
            relations: [new FakeRelationMetadata('parent', ['labels'], false, relatedEndpoint: false, relationshipEndpoint: $parentRelationshipEndpoint)],
            operations: $labelOperations,
            actions: $labelActions,
            relatedOnly: $relatedOnly,
        );
        $albums = FakeTypeMetadata::resource(
            type: 'albums',
            fields: [Id::make()->example('alb-1')->build(), Str::make('title')->example('Debut')->build()],
            relations: [new FakeRelationMetadata('label', ['labels'], false)],
            actions: [new FakeActionMetadata('relabel', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'labels')],
        );

        $server = new FakeServerMetadata(
            title: 'Related Only API',
            version: '1.0.0',
            types: [$labels, $albums],
            servers: [new Server('https://api.example.com')],
        );

        /** @var array<string, mixed> */
        return (new OpenApiProjector())->project($server)->toArray();
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> */
        return $value;
    }
}
