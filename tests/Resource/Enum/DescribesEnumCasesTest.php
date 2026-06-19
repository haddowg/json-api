<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Enum;

use haddowg\JsonApi\Resource\Enum\EnumCaseDescription;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumCaseDescription::class)]
#[Group('spec:document-structure')]
final class DescribesEnumCasesTest extends TestCase
{
    #[Test]
    public function describedCasesReturnTheirDescription(): void
    {
        self::assertSame('Not yet visible to readers', Status::Draft->description());
        self::assertSame('Live and public', Status::Published->description());
    }

    #[Test]
    public function anUndescribedCaseReturnsNull(): void
    {
        self::assertNull(Status::Archived->description());
    }

    #[Test]
    public function descriptionsMapsBackingValuesToDescriptionsAndOmitsUndescribedCases(): void
    {
        self::assertSame(
            [
                'draft' => 'Not yet visible to readers',
                'published' => 'Live and public',
            ],
            Status::descriptions(),
        );
    }
}
