<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Relationship;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\RelationshipLinks;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\FakeRelationship;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class AbstractRelationshipTest extends TestCase
{
    #[Test]
    public function createWithData(): void
    {
        $relationship = FakeRelationship::createWithData([], new StubResource());

        self::assertEquals([], $relationship->getRelationshipData());
    }

    #[Test]
    public function createWithLinks(): void
    {
        $relationship = FakeRelationship::createWithLinks(new RelationshipLinks());

        self::assertNotNull($relationship->getLinks());
    }

    #[Test]
    public function createWithMeta(): void
    {
        $relationship = FakeRelationship::createWithMeta(['abc' => 'def']);

        self::assertEquals(['abc' => 'def'], $relationship->getMeta());
    }

    #[Test]
    public function setLinks(): void
    {
        $relationship = FakeRelationship::create();

        $relationship->setLinks(new RelationshipLinks());

        self::assertNotNull($relationship->getLinks());
    }

    #[Test]
    public function setDataSetsRelationshipData(): void
    {
        $relationship = $this->createRelationship();

        $relationship->setData(['id' => 1], new StubResource());

        self::assertEquals(['id' => 1], $relationship->getRelationshipData());
    }

    #[Test]
    public function setDataAsCallable(): void
    {
        $relationship = $this->createRelationship();

        $relationship->setDataAsCallable(
            static fn(): array => ['id' => 1],
            new StubResource(),
        );

        self::assertEquals(['id' => 1], $relationship->getRelationshipData());
    }

    #[Test]
    public function dataNotOmittedByDefault(): void
    {
        $relationship = $this->createRelationship();

        self::assertFalse($relationship->isOmitDataWhenNotIncluded());
    }

    #[Test]
    public function omitDataWhenNotIncluded(): void
    {
        $relationship = $this->createRelationship();

        $relationship->omitDataWhenNotIncluded();

        self::assertTrue($relationship->isOmitDataWhenNotIncluded());
    }

    #[Test]
    public function transformWithMeta(): void
    {
        $relationship = $this->createRelationship()
            ->setMeta(['abc' => 'def']);

        $relationshipObject = $relationship->transform(
            $this->createTransformation(),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'meta' => [
                    'abc' => 'def',
                ],
                'data' => [],
            ],
            $relationshipObject,
        );
    }

    #[Test]
    public function transformWithLinks(): void
    {
        $relationship = $this->createRelationship()
            ->setLinks(new RelationshipLinks());

        $relationshipObject = $relationship->transform(
            $this->createTransformation(),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'links' => [],
                'data' => [],
            ],
            $relationshipObject,
        );
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function transformWhenNotIncludedField(): void
    {
        $relationship = $this->createRelationship();

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('user1'),
                [],
                'user1',
                new StubJsonApiRequest(['fields' => ['user1' => '']]),
                '',
                'rel',
                'rel',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertNull($relationshipObject);
    }

    #[Test]
    public function transformWithEmptyData(): void
    {
        $relationship = $this->createRelationship();

        $relationshipObject = $relationship->transform(
            $this->createTransformation(),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'data' => [],
            ],
            $relationshipObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformForcesDataWhenOmittedButNoLinksOrMeta(): void
    {
        // The validity guard: a relationship that omits its data when not included but
        // would render neither links nor meta must still emit `data`, since a
        // relationship object can never be empty `{}` (JSON:API requires at least one
        // of links / meta / data).
        $relationship = $this->createRelationship()
            ->omitDataWhenNotIncluded();

        $relationshipObject = $relationship->transform(
            $this->createTransformation(),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(['data' => []], $relationshipObject);
    }

    #[Test]
    public function transformWithEmptyOmittedDataWhenRelationship(): void
    {
        $relationship = $this->createRelationship()
            ->omitDataWhenNotIncluded();

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource(),
                [],
                '',
                new StubJsonApiRequest(),
                '',
                'dummy',
                'dummy',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'data' => [],
            ],
            $relationshipObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformEmitsConventionSelfAndRelatedLinks(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('author');

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'links' => [
                    'self' => 'https://api.example.com/articles/42/relationships/author',
                    'related' => 'https://api.example.com/articles/42/author',
                ],
                'data' => [],
            ],
            $relationshipObject,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformEmitsOnlyRelatedConventionLinkWhenSelfSuppressed(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('author', false, true);

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'related' => 'https://api.example.com/articles/42/author',
            ],
            $relationshipObject['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformEmitsOnlySelfConventionLinkWhenRelatedSuppressed(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('author', true, false);

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'self' => 'https://api.example.com/articles/42/relationships/author',
            ],
            $relationshipObject['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformEmitsConventionLinksUsingTheParentUriType(): void
    {
        // The parent's JSON:API type is `article`, but its URI segment is
        // `articles`; the convention links must use the segment, so a URI-type-aware
        // parent's links match the routes that emit it.
        $relationship = $this->createRelationship()
            ->withConventionLinks('author');

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new SegmentedParentResource(),
                ['id' => '42'],
                'article',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'self' => 'https://api.example.com/articles/42/relationships/author',
                'related' => 'https://api.example.com/articles/42/author',
            ],
            $relationshipObject['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformEmitsConventionLinksWithUriFieldNameOverride(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('writer');

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            [
                'self' => 'https://api.example.com/articles/42/relationships/writer',
                'related' => 'https://api.example.com/articles/42/writer',
            ],
            $relationshipObject['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformOmitsConventionLinksWhenNotRequested(): void
    {
        $relationshipObject = $this->createRelationship()->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertArrayNotHasKey('links', (array) $relationshipObject);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function transformOmitsConventionLinksWhenParentIdUnavailable(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('author');

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', ''),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertArrayNotHasKey('links', (array) $relationshipObject);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function explicitLinksTakePrecedenceOverConventionLinks(): void
    {
        $relationship = $this->createRelationship()
            ->withConventionLinks('author')
            ->setLinks(new RelationshipLinks('', new Link('/custom/self')));

        $relationshipObject = $relationship->transform(
            new ResourceTransformation(
                new StubResource('articles', '42'),
                [],
                'articles',
                new StubJsonApiRequest(),
                '',
                '',
                '',
                'https://api.example.com',
            ),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertSame(
            ['self' => '/custom/self'],
            $relationshipObject['links'] ?? null,
        );
    }

    private function createTransformation(): ResourceTransformation
    {
        return new ResourceTransformation(
            new StubResource(),
            [],
            '',
            new StubJsonApiRequest(),
            '',
            '',
            '',
        );
    }

    private function createRelationship(): FakeRelationship
    {
        return new FakeRelationship();
    }
}

/**
 * A parent resource whose URI segment (`articles`) differs from its JSON:API
 * type (`article`), so the convention links exercise the URI-type-aware path.
 */
final class SegmentedParentResource extends AbstractResource
{
    public static string $type = 'article';

    public static string $uriType = 'articles';

    public function fields(): array
    {
        return [
            Id::make(),
        ];
    }
}
