<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Schema\Document\ResourceDocumentInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResourceDocument;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractResourceDocumentTest extends TestCase
{
    #[Test]
    public function initializeTransformation(): void
    {
        $document = $this->createDocument();
        $transformation = $this->createTransformation($document);

        $document->initializeTransformation($transformation);

        self::assertSame($transformation->request, $document->getRequest());
        self::assertSame($transformation->object, $document->getObject());
    }

    #[Test]
    public function clearTransformation(): void
    {
        $document = $this->createDocument();
        $transformation = $this->createTransformation($document);

        $document->initializeTransformation($transformation);
        $document->clearTransformation();

        self::assertNotNull($document->getRequest());
        self::assertNotNull($document->getObject());
    }

    private function createTransformation(ResourceDocumentInterface $document): ResourceDocumentTransformation
    {
        return new ResourceDocumentTransformation(
            $document,
            [],
            new StubJsonApiRequest(),
            '',
            '',
            [],
        );
    }

    private function createDocument(): StubResourceDocument
    {
        return new StubResourceDocument();
    }
}
