<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Error;

use haddowg\JsonApi\Schema\Error\ErrorSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ErrorSourceTest extends TestCase
{
    #[Test]
    public function fromPointer(): void
    {
        $source = ErrorSource::fromPointer('/data/attributes/title');

        self::assertSame('/data/attributes/title', $source->pointer);
        self::assertSame('', $source->parameter);
    }

    #[Test]
    public function fromParameter(): void
    {
        $source = ErrorSource::fromParameter('include');

        self::assertSame('', $source->pointer);
        self::assertSame('include', $source->parameter);
    }

    #[Test]
    public function transformOmitsEmptyMembers(): void
    {
        self::assertSame(
            ['pointer' => '/data'],
            ErrorSource::fromPointer('/data')->transform(),
        );
        self::assertSame(
            ['parameter' => 'include'],
            ErrorSource::fromParameter('include')->transform(),
        );
    }

    #[Test]
    public function transformIncludesBothMembers(): void
    {
        $source = new ErrorSource('/data', 'include');

        self::assertSame(
            ['pointer' => '/data', 'parameter' => 'include'],
            $source->transform(),
        );
    }
}
