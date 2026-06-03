<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Error;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InternalServerErrorTest extends TestCase
{
    #[Test]
    #[Group('spec:errors')]
    public function redactedModeReturnsOnlyStatusAndTitle(): void
    {
        $error = InternalServerError::for(new \RuntimeException('leaky secret detail', 42));

        self::assertSame('500', $error->status);
        self::assertSame('Internal Server Error', $error->title);
        self::assertSame('', $error->code);
        self::assertSame('', $error->detail);
        self::assertSame([], $error->meta);
        self::assertNull($error->source);
        self::assertNull($error->links);

        // transform() locks the omitted-member behaviour: only status + title.
        self::assertSame(
            ['status' => '500', 'title' => 'Internal Server Error'],
            $error->transform(),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function theDefaultArgumentIsTheRedactedForm(): void
    {
        $explicit = InternalServerError::for(new \RuntimeException('boom'), false);
        $defaulted = InternalServerError::for(new \RuntimeException('boom'));

        self::assertEquals($explicit->transform(), $defaulted->transform());
        self::assertSame(
            ['status' => '500', 'title' => 'Internal Server Error'],
            $defaulted->transform(),
        );
    }

    #[Test]
    #[Group('spec:errors')]
    public function debugModeExposesCodeDetailAndMeta(): void
    {
        $throwable = new \RuntimeException('leaky secret detail', 42);

        $error = InternalServerError::for($throwable, true);

        self::assertSame('500', $error->status);
        self::assertSame('42', $error->code);
        self::assertSame('Internal Server Error', $error->title);
        self::assertSame('leaky secret detail', $error->detail);

        self::assertSame(\RuntimeException::class, $error->meta['exception']);
        self::assertSame($throwable->getFile(), $error->meta['file']);
        self::assertSame($throwable->getLine(), $error->meta['line']);
        self::assertIsInt($error->meta['line']);
        self::assertIsArray($error->meta['trace']);
    }

    #[Test]
    #[Group('spec:errors')]
    public function debugModeCollapsesZeroCodeToEmptyStringWhichTransformOmits(): void
    {
        $error = InternalServerError::for(new \RuntimeException('no code'), true);

        self::assertSame('', $error->code);

        $transformed = $error->transform();
        self::assertArrayNotHasKey('code', $transformed);
    }

    #[Test]
    #[Group('spec:errors')]
    public function debugMetaKeyOrderIsExactlyExceptionFileLineTrace(): void
    {
        $error = InternalServerError::for(new \RuntimeException('boom', 7), true);

        self::assertSame(
            ['exception', 'file', 'line', 'trace'],
            \array_keys($error->meta),
        );
    }

    #[Test]
    public function traceStripsArgsFromEveryFrameAndPreservesFrameCount(): void
    {
        try {
            throw new \RuntimeException('boom');
        } catch (\RuntimeException $throwable) {
            $error = InternalServerError::for($throwable, true);
        }

        /** @var list<array<string, mixed>> $trace */
        $trace = $error->meta['trace'];

        self::assertCount(\count($throwable->getTrace()), $trace);

        foreach ($trace as $frame) {
            self::assertArrayNotHasKey('args', $frame);
        }
    }

    #[Test]
    public function theMappingIsPureAndReturnsAFreshErrorEachCall(): void
    {
        $throwable = new \RuntimeException('boom', 1);

        $first = InternalServerError::for($throwable, true);
        $second = InternalServerError::for($throwable, true);

        self::assertInstanceOf(Error::class, $first);
        self::assertNotSame($first, $second);
        self::assertEquals($first, $second);
    }
}
