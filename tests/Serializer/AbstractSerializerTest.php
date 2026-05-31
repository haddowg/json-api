<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Serializer;

use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Transformer\ResourceTransformation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AbstractSerializerTest extends TestCase
{
    #[Test]
    public function initializeTransformation(): void
    {
        $resource = $this->createResource();
        $transformation = $this->createTransformation($resource);

        $resource->initializeTransformation(
            $transformation->request,
            $transformation->object,
        );

        self::assertSame($transformation->request, $resource->getRequest());
        self::assertSame($transformation->object, $resource->getObject());
    }

    #[Test]
    public function clearTransformation(): void
    {
        $resource = $this->createResource();
        $transformation = $this->createTransformation($resource);

        $resource->initializeTransformation(
            $transformation->request,
            $transformation->object,
        );
        $resource->clearTransformation();

        self::assertNull($resource->getRequest());
        self::assertNull($resource->getObject());
    }

    private function createResource(): StubResource
    {
        return new StubResource();
    }

    private function createTransformation(SerializerInterface $resource): ResourceTransformation
    {
        return new ResourceTransformation(
            $resource,
            [],
            '',
            new StubJsonApiRequest(),
            '',
            '',
            '',
        );
    }
}
