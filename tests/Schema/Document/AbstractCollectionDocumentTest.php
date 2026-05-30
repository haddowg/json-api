<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Schema\Resource\ResourceInterface;
use haddowg\JsonApi\Tests\Double\StubCollectionDocument;
use haddowg\JsonApi\Tests\Double\StubResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractCollectionDocumentTest extends TestCase
{
    #[Test]
    public function getResource(): void
    {
        $resource = new StubResource();

        $collectionDocument = $this->createCollectionDocument($resource);

        self::assertSame($resource, $collectionDocument->getResource());
    }

    #[Test]
    public function hasItemsTrue(): void
    {
        $collectionDocument = $this->createCollectionDocument(new StubResource(), [[], []]);

        self::assertTrue($collectionDocument->getHasItems());
    }

    #[Test]
    public function hasItemsFalse(): void
    {
        $collectionDocument = $this->createCollectionDocument(new StubResource(), []);

        self::assertFalse($collectionDocument->getHasItems());
    }

    /**
     * @param iterable<mixed> $object
     */
    private function createCollectionDocument(?ResourceInterface $resource = null, iterable $object = []): StubCollectionDocument
    {
        return new StubCollectionDocument(null, [], null, $resource, $object);
    }
}
