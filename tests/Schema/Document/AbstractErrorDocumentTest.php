<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Document;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Tests\Double\StubErrorDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class AbstractErrorDocumentTest extends TestCase
{
    #[Test]
    public function getErrorsWhenEmpty(): void
    {
        $errorDocument = $this->createErrorDocument();

        self::assertEquals([], $errorDocument->getErrors());
    }

    #[Test]
    public function getErrors(): void
    {
        $errorDocument = $this->createErrorDocument()
            ->addError(new Error())
            ->addError(new Error());

        self::assertEquals([new Error(), new Error()], $errorDocument->getErrors());
    }

    #[Test]
    public function getStatusCodeWithOneErrorInDocument(): void
    {
        $errorDocument = $this->createErrorDocument()
            ->addError(new Error(status: '404'));

        self::assertSame(404, $errorDocument->getStatusCode());
    }

    #[Test]
    public function getStatusCodeWithErrorInParameter(): void
    {
        $errorDocument = $this->createErrorDocument()
            ->addError(new Error());

        self::assertSame(404, $errorDocument->getStatusCode(404));
    }

    #[Test]
    public function getStatusCodeWithMultipleErrorsInDocument(): void
    {
        $errorDocument = $this->createErrorDocument()
            ->addError(new Error(status: '418'))
            ->addError(new Error(status: '404'));

        self::assertSame(400, $errorDocument->getStatusCode());
    }

    #[Test]
    public function getStatusCodeWithMultipleErrorsSharingOneStatus(): void
    {
        $errorDocument = $this->createErrorDocument()
            ->addError(new Error(status: '422'))
            ->addError(new Error(status: '422'));

        // A uniform set keeps its status rather than rounding down to a class.
        self::assertSame(422, $errorDocument->getStatusCode());
    }

    #[Test]
    public function getStatusCodeWithNoErrorsDefaultsToServerError(): void
    {
        self::assertSame(500, $this->createErrorDocument()->getStatusCode());
    }

    private function createErrorDocument(): StubErrorDocument
    {
        return new StubErrorDocument();
    }
}
