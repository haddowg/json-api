<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Schema\Document\ErrorDocument;
use haddowg\JsonApi\Schema\JsonApiObject;
use haddowg\JsonApi\Schema\Link\DocumentLinks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ErrorDocumentTest extends TestCase
{
    #[Test]
    public function getJsonApi(): void
    {
        $errorDocument = $this->createErrorDocument();

        $errorDocument->setJsonApi(new JsonApiObject('1.0'));

        self::assertEquals(new JsonApiObject('1.0'), $errorDocument->getJsonApi());
    }

    #[Test]
    public function getMeta(): void
    {
        $errorDocument = $this->createErrorDocument();

        $errorDocument->setMeta(['abc' => 'def']);

        self::assertEquals(['abc' => 'def'], $errorDocument->getMeta());
    }

    #[Test]
    public function getLinks(): void
    {
        $errorDocument = $this->createErrorDocument();

        $errorDocument->setLinks(new DocumentLinks('https://example.com'));

        self::assertEquals(new DocumentLinks('https://example.com'), $errorDocument->getLinks());
    }

    private function createErrorDocument(): ErrorDocument
    {
        return new ErrorDocument();
    }
}
