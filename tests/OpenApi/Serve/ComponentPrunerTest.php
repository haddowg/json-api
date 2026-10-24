<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\Serve\ComponentPruner;
use haddowg\JsonApi\OpenApi\Serve\PruneBaseline;
use haddowg\JsonApi\OpenApi\Serve\ServeArtifact;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ContractWitnessServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentPruner::class)]
#[CoversClass(PruneBaseline::class)]
#[CoversClass(ServeArtifact::class)]
final class ComponentPrunerTest extends TestCase
{
    #[Test]
    public function itDropsWhatRemovedOperationsAloneReachedTransitively(): void
    {
        $baseline = ComponentPruner::baseline(self::document());

        $served = self::document();
        unset($served->paths->{'/b'}->get);

        $pruned = ComponentPruner::prune($served, $baseline);

        self::assertObjectNotHasProperty('/b', $pruned->paths);
        // B and its discriminator target BChild go; Shared stays because A still uses it;
        // Unused was never referenced, so it stays and keeps what it references.
        self::assertSame(['A', 'Shared', 'Unused', 'UsedByUnused'], self::names($pruned->components->schemas));
        self::assertObjectNotHasProperty('responses', $pruned->components);
        self::assertObjectHasProperty('securitySchemes', $pruned->components);
        self::assertSame(['a', 'configured'], \array_map(static fn(\stdClass $tag): mixed => $tag->name, $pruned->tags));
    }

    #[Test]
    public function aDiscriminatorMappingKeepsItsTargetReachable(): void
    {
        $baseline = ComponentPruner::baseline(self::document());

        $served = self::document();
        unset($served->paths->{'/a'});

        $pruned = ComponentPruner::prune($served, $baseline);

        // A goes; BChild is named only by B's discriminator mapping and stays with B.
        self::assertSame(['B', 'BChild', 'Shared', 'Unused', 'UsedByUnused'], self::names($pruned->components->schemas));
    }

    #[Test]
    public function aComponentADecoratorAddsIsARoot(): void
    {
        $baseline = ComponentPruner::baseline(self::document());

        $served = self::document();
        unset($served->paths->{'/b'}->get);
        $served->components->schemas->Added = (object) ['$ref' => '#/components/schemas/B'];

        $pruned = ComponentPruner::prune($served, $baseline);

        self::assertObjectHasProperty('Added', $pruned->components->schemas);
        self::assertObjectHasProperty('B', $pruned->components->schemas);
        self::assertObjectHasProperty('BChild', $pruned->components->schemas);
    }

    #[Test]
    public function anUndecoratedDocumentIsLeftAsItWas(): void
    {
        $baseline = ComponentPruner::baseline(self::document());

        self::assertEquals(self::document(), ComponentPruner::prune(self::document(), $baseline));
    }

    #[Test]
    public function anEscapedComponentNameIsResolved(): void
    {
        $document = self::decode(<<<'JSON'
            {
              "paths": {"/a": {"get": {"responses": {"200": {"$ref": "#/components/responses/a~1b"}}}}},
              "components": {
                "responses": {"a/b": {"description": "x", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/Inner/properties/x"}}}}},
                "schemas": {"Inner": {"properties": {"x": {"type": "string"}}}}
              }
            }
            JSON);
        $baseline = ComponentPruner::baseline($document);

        self::assertArrayHasKey('#/components/responses/a~1b', $baseline->reachable);
        self::assertArrayHasKey('#/components/schemas/Inner', $baseline->reachable);

        unset($document->paths->{'/a'});
        $pruned = ComponentPruner::prune($document, $baseline);

        self::assertObjectNotHasProperty('paths', $pruned);
        self::assertObjectNotHasProperty('responses', $pruned->components);
        self::assertObjectNotHasProperty('schemas', $pruned->components);
    }

    #[Test]
    public function aProjectedDocumentKeepsEveryReferenceResolvableAfterPruning(): void
    {
        $artifact = ServeArtifact::of((new OpenApiProjector())->project(ContractWitnessServer::build()));

        $served = $artifact->document->toJson();
        foreach (\array_keys(\get_object_vars($served->paths)) as $path) {
            if (\str_starts_with($path, '/tags')) {
                unset($served->paths->{$path});
            }
        }
        $pruned = ComponentPruner::prune($served, $artifact->baseline);

        $schemas = $pruned->components->schemas;
        self::assertObjectNotHasProperty('TagsCreateRequest', $schemas);
        self::assertObjectNotHasProperty('TagsArticlesRelationshipDocument', $schemas);
        // The articles' tags linkage still renders on the wire, so its members stay.
        self::assertObjectHasProperty('ArticlesTagsRelationship', $schemas);
        self::assertObjectHasProperty('TagsResourceIdentifier', $schemas);
        self::assertObjectHasProperty('securitySchemes', $pruned->components);

        // A declared response may name a component the server never defines; only what
        // resolved before pruning has to resolve after it.
        $original = $artifact->document->toJson();
        $dangling = \array_filter(\array_unique(self::refs($original)), static fn(string $ref): bool => !self::resolves($original, $ref));
        foreach (\array_diff(\array_unique(self::refs($pruned)), $dangling) as $ref) {
            self::assertTrue(self::resolves($pruned, $ref), $ref . ' dangles after pruning');
        }
    }

    #[Test]
    public function aProjectedDocumentNobodyDecoratedIsUnchanged(): void
    {
        $artifact = ServeArtifact::of((new OpenApiProjector())->project(ContractWitnessServer::build()));

        self::assertEquals($artifact->document->toJson(), $artifact->prune($artifact->document));
    }

    /**
     * @return list<string>
     */
    private static function names(\stdClass $section): array
    {
        $names = \array_keys(\get_object_vars($section));
        \sort($names);

        return $names;
    }

    private static function resolves(\stdClass $document, string $ref): bool
    {
        [$section, $name] = \explode('/', \substr($ref, \strlen('#/components/')), 3) + [1 => ''];
        $entries = $document->components->{$section} ?? null;

        return $entries instanceof \stdClass && \property_exists($entries, \str_replace(['~1', '~0'], ['/', '~'], $name));
    }

    /**
     * @return list<string>
     */
    private static function refs(mixed $node): array
    {
        if (!\is_array($node) && !$node instanceof \stdClass) {
            return [];
        }

        $refs = [];
        foreach (($node instanceof \stdClass ? \get_object_vars($node) : $node) as $key => $value) {
            if ($key === '$ref' && \is_string($value) && \str_starts_with($value, '#/components/')) {
                $refs[] = $value;
            }
            $refs = [...$refs, ...self::refs($value)];
        }

        return $refs;
    }

    private static function document(): \stdClass
    {
        return self::decode(<<<'JSON'
            {
              "openapi": "3.1.0",
              "tags": [{"name": "a"}, {"name": "b"}, {"name": "configured"}],
              "paths": {
                "/a": {"get": {"tags": ["a"], "responses": {"200": {"content": {"application/json": {"schema": {"$ref": "#/components/schemas/A"}}}}}}},
                "/b": {"get": {"tags": ["b"], "responses": {"200": {"$ref": "#/components/responses/BResponse"}}}}
              },
              "components": {
                "schemas": {
                  "A": {"properties": {"shared": {"$ref": "#/components/schemas/Shared"}}},
                  "B": {"oneOf": [{"$ref": "#/components/schemas/Shared"}], "discriminator": {"propertyName": "t", "mapping": {"child": "#/components/schemas/BChild"}}},
                  "BChild": {"type": "object"},
                  "Shared": {"type": "string"},
                  "Unused": {"properties": {"x": {"$ref": "#/components/schemas/UsedByUnused"}}},
                  "UsedByUnused": {"type": "integer"}
                },
                "responses": {
                  "BResponse": {"description": "B", "content": {"application/json": {"schema": {"$ref": "#/components/schemas/B"}}}}
                },
                "securitySchemes": {"bearer": {"type": "http", "scheme": "bearer"}}
              }
            }
            JSON);
    }

    private static function decode(string $json): \stdClass
    {
        $decoded = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded);

        return $decoded;
    }
}
