<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Schema\Document\ResourceDocumentInterface;
use haddowg\JsonApi\Tests\Double\DummyData;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResourceDocument;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractResourceDocumentTest extends TestCase
{
    #[Test]
    public function getDataReceivesTheTransformationObjectStatelessly(): void
    {
        $object = (object) ['id' => '1'];
        $document = new StubResourceDocument(data: new DummyData());

        // The document holds no per-pass state: the object travels on the
        // transformation and reaches getData() directly.
        $result = (new DocumentTransformer())
            ->transformResourceDocument($this->createTransformation($document, $object))
            ->result;

        self::assertArrayHasKey('data', $result);
    }

    private function createTransformation(
        ResourceDocumentInterface $document,
        mixed $object,
    ): ResourceDocumentTransformation {
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
