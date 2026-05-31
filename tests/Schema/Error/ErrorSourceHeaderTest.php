<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema\Error;

use haddowg\JsonApi\Schema\Error\ErrorSource;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ErrorSourceHeaderTest extends TestCase
{
    #[Test]
    public function fromHeaderExposesAndEmitsTheHeader(): void
    {
        $source = ErrorSource::fromHeader('Authorization');

        self::assertSame('Authorization', $source->header);
        self::assertSame('', $source->pointer);
        self::assertSame('', $source->parameter);
        self::assertSame(['header' => 'Authorization'], $source->transform());
    }

    #[Test]
    public function emitsAllThreeMembersWhenSet(): void
    {
        $source = new ErrorSource('/data/attributes/title', 'include', 'Accept');

        self::assertSame(
            ['pointer' => '/data/attributes/title', 'parameter' => 'include', 'header' => 'Accept'],
            $source->transform(),
        );
    }

    #[Test]
    public function omitsHeaderWhenEmpty(): void
    {
        self::assertSame(['pointer' => '/data'], ErrorSource::fromPointer('/data')->transform());
    }
}
