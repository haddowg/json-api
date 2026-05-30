<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Relationship;

use haddowg\JsonApi\Schema\Link\RelationshipLinks;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationship;
use haddowg\JsonApi\Schema\Resource\ResourceInterface;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use haddowg\JsonApi\Transformer\ResourceTransformer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class ToOneRelationshipTest extends TestCase
{
    #[Test]
    public function transformEmpty(): void
    {
        $transformation = $this->createTransformation();
        $relationship = $this->createRelationship();

        $relationshipObject = $relationship->transform(
            $transformation,
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals([], $relationshipObject);
    }

    #[Test]
    public function transformNull(): void
    {
        $transformation = $this->createTransformation();
        $relationship = $this->createRelationship([], null, null, $transformation->resource);

        $relationshipObject = $relationship->transform(
            $transformation,
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'data' => null,
            ],
            $relationshipObject,
        );
    }

    #[Test]
    public function transform(): void
    {
        $relationship = $this->createRelationship(
            [],
            null,
            [],
            new StubResource('abc', '1'),
        );

        $relationshipObject = $relationship->transform(
            $this->createTransformation(),
            new ResourceTransformer(),
            new DummyData(),
            [],
        );

        self::assertEquals(
            [
                'data' => [
                    'type' => 'abc',
                    'id' => '1',
                ],
            ],
            $relationshipObject,
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

    /**
     * @param array<string, mixed>    $meta
     * @param array<int|string, mixed>|null $data
     */
    private function createRelationship(
        array $meta = [],
        ?RelationshipLinks $links = null,
        ?array $data = [],
        ?ResourceInterface $resource = null,
    ): ToOneRelationship {
        return new ToOneRelationship($meta, $links, $data, $resource);
    }
}
