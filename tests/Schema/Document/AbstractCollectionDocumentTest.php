<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Tests\Double\StubCollectionDocument;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubSerializer;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractCollectionDocumentTest extends TestCase
{
    #[Test]
    public function getResource(): void
    {
        $resource = new StubResource();

        $collectionDocument = new StubCollectionDocument(null, [], null, $resource);

        self::assertSame($resource, $collectionDocument->getResource());
    }

    #[Test]
    public function transformsEachItemFromTheTransformationCollection(): void
    {
        // StubSerializer reads the id from each array item, so the two items
        // produce distinct resource identifiers.
        $document = new StubCollectionDocument(null, [], null, new StubSerializer('articles'));

        // The collection travels on the transformation, not on the document: the
        // stateless document reads its items from there.
        $result = (new DocumentTransformer())
            ->transformResourceDocument($this->createTransformation($document, [['id' => '1'], ['id' => '2']]))
            ->result;

        self::assertIsArray($result['data']);
        self::assertCount(2, $result['data']);
    }

    #[Test]
    public function anEmptyCollectionTransformsToEmptyData(): void
    {
        $document = new StubCollectionDocument(null, [], null, new StubResource('articles', '1'));

        $result = (new DocumentTransformer())
            ->transformResourceDocument($this->createTransformation($document, []))
            ->result;

        self::assertSame([], $result['data']);
    }

    /**
     * @param iterable<mixed> $object
     */
    private function createTransformation(StubCollectionDocument $document, iterable $object): ResourceDocumentTransformation
    {
        return new ResourceDocumentTransformation(
            $document,
            $object,
            new StubJsonApiRequest(),
            '',
            '',
            [],
        );
    }
}
