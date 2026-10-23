<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each endpoint's `page` parameter carries the numbers of the paginator that serves it,
 * and `withCount` tells a client where each requested total lands.
 */
#[CoversClass(OperationProjector::class)]
#[CoversClass(OpenApiProjector::class)]
#[Group('spec:document-structure')]
final class PaginatorLimitsProjectionTest extends TestCase
{
    private const OAS_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    private function server(): FakeServerMetadata
    {
        $articles = FakeTypeMetadata::resource(
            type: 'articles',
            fields: [Id::make()->build(), Str::make('title')->build()],
            relations: [
                new FakeRelationMetadata(
                    'comments',
                    ['comments'],
                    true,
                    countable: true,
                    pageSchema: CursorPaginator::make()->withDefaultSize(5)->withMaxPerPage(25)->describePageSchema(),
                ),
            ],
            pageSchema: PagePaginator::make()->withDefaultPerPage(25)->withMaxPerPage(100)->describePageSchema(),
            countable: true,
        );
        $comments = FakeTypeMetadata::resource(
            type: 'comments',
            fields: [Id::make()->build(), Str::make('body')->build()],
            relations: [new FakeRelationMetadata('likes', ['likes'], true, countable: true)],
            pageSchema: (new MultiPaginator(
                PagePaginator::make()->withDefaultPerPage(10)->withMaxPerPage(30),
                OffsetPaginator::make()->withDefaultLimit(40)->withMaxPerPage(0),
            ))->describePageSchema(),
        );
        $likes = FakeTypeMetadata::resource(type: 'likes', fields: [Id::make()->build()]);

        return new FakeServerMetadata(
            title: 'Blog API',
            version: '1.0.0',
            types: [$articles, $comments, $likes],
            profiles: [CountableProfile::URI],
        );
    }

    #[Test]
    public function aCollectionPageParameterCarriesItsResourcesNumbers(): void
    {
        $page = $this->pageSchema('/articles');

        self::assertSame(1, $this->at($page, 'properties', 'number', 'default'));
        self::assertSame(25, $this->at($page, 'properties', 'size', 'default'));
        self::assertSame(100, $this->at($page, 'properties', 'size', 'maximum'));
    }

    #[Test]
    public function aRelatedEndpointCarriesItsRelationPaginatorsNumbers(): void
    {
        $page = $this->pageSchema('/articles/{id}/comments');

        self::assertSame(5, $this->at($page, 'properties', 'size', 'default'));
        self::assertSame(25, $this->at($page, 'properties', 'size', 'maximum'));
    }

    #[Test]
    public function eachMenuArmCarriesItsOwnNumbers(): void
    {
        $branches = $this->pageSchema('/comments')['oneOf'] ?? null;
        self::assertIsArray($branches);
        [$page, $offset] = \array_map(static fn(mixed $branch): array => \is_array($branch) ? $branch : [], \array_values($branches));

        self::assertSame(10, $page['properties']['size']['default'] ?? null);
        self::assertSame(30, $page['properties']['size']['maximum'] ?? null);
        self::assertSame(40, $offset['properties']['limit']['default'] ?? null);
        self::assertIsArray($offset['properties']['limit'] ?? null);
        self::assertArrayNotHasKey('maximum', $offset['properties']['limit']);
    }

    #[Test]
    public function theWithCountDescriptionNamesWhereEachTotalLands(): void
    {
        $collection = $this->withCountDescription('/articles');
        self::assertStringContainsString('Request `withCount=_self_` to receive the collection total in the top-level `meta.total` (also in `meta.page.total` when the collection is paginated).', $collection);
        self::assertStringContainsString('Request a relation name, e.g. `withCount=comments`, to receive that relation\'s total on each resource in `relationships.comments.meta.total`.', $collection);
        self::assertStringEndsWith('Allowed tokens: `_self_`, `comments`.', $collection);

        // The related endpoint counts its own collection and the related type's relations.
        $related = $this->withCountDescription('/articles/{id}/comments');
        self::assertStringContainsString('`withCount=_self_`', $related);
        self::assertStringContainsString('`relationships.likes.meta.total`', $related);

        // The linkage endpoint takes only `_self_`, so it names no relation token.
        $linkage = $this->withCountDescription('/articles/{id}/relationships/comments');
        self::assertStringContainsString('`withCount=_self_`', $linkage);
        self::assertStringNotContainsString('relation name', $linkage);

        // A collection with only relation tokens names no `_self_`.
        $comments = $this->withCountDescription('/comments');
        self::assertStringNotContainsString('_self_', $comments);
        self::assertStringContainsString('`withCount=likes`', $comments);
    }

    #[Test]
    public function theDocumentValidatesAgainstTheOpenApiMetaSchema(): void
    {
        $document = $this->document();

        $result = $this->oasValidator()->validate($document->toJson(), self::OAS_SCHEMA_ID);

        self::assertTrue($result->isValid(), 'Not a valid OpenAPI 3.1 document: ' . $document->toJsonString(true));
    }

    private function document(): OpenApi
    {
        return (new OpenApiProjector())->project($this->server());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function pageSchema(string $path): array
    {
        $schema = $this->parameterNamed($path, 'page')['schema'] ?? null;
        self::assertIsArray($schema);

        return $schema;
    }

    private function withCountDescription(string $path): string
    {
        $description = $this->parameterNamed($path, 'withCount')['description'] ?? null;
        self::assertIsString($description);

        return $description;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parameterNamed(string $path, string $name): array
    {
        $parameters = $this->at($this->document()->toArray(), 'paths', $path, 'get', 'parameters');
        self::assertIsArray($parameters);
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail("Parameter {$name} not found on GET {$path}");
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function at(array $node, string ...$keys): mixed
    {
        $cursor = $node;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor);
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    private function oasValidator(): Validator
    {
        $validator = new Validator();
        $resolver = $validator->resolver();
        self::assertNotNull($resolver);

        $register = function (string $base, array $documents) use ($resolver): void {
            foreach ($documents as $document) {
                $raw = \file_get_contents($base . $document);
                self::assertIsString($raw);
                $decoded = \json_decode($raw);
                self::assertInstanceOf(\stdClass::class, $decoded);
                $id = $decoded->{'$id'} ?? null;
                self::assertIsString($id);
                $resolver->registerRaw($decoded, $id);
            }
        };

        $register(__DIR__ . '/Fixture/meta-schema/', [
            'schema.json', 'meta/core.json', 'meta/applicator.json', 'meta/unevaluated.json',
            'meta/validation.json', 'meta/meta-data.json', 'meta/format-annotation.json', 'meta/content.json',
        ]);
        $register(__DIR__ . '/Fixture/oas-3.1/', ['schema.json', 'dialect.json', 'meta/base.json']);

        return $validator;
    }
}
