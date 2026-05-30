<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Schema;

use haddowg\JsonApi\Schema\JsonApiObject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:document-structure')]
final class JsonApiObjectTest extends TestCase
{
    #[Test]
    public function exposesVersionAndMeta(): void
    {
        $jsonApi = new JsonApiObject('1.1', ['abc' => 'def']);

        self::assertSame('1.1', $jsonApi->version);
        self::assertSame(['abc' => 'def'], $jsonApi->meta);
    }

    #[Test]
    public function defaultsToVersion11(): void
    {
        self::assertSame('1.1', (new JsonApiObject())->version);
    }

    #[Test]
    public function transformOmitsEmptyVersion(): void
    {
        $jsonApi = new JsonApiObject('', ['abc' => 'def']);

        self::assertSame(['meta' => ['abc' => 'def']], $jsonApi->transform());
    }

    #[Test]
    public function transformOmitsEmptyMeta(): void
    {
        $jsonApi = new JsonApiObject('1.1');

        self::assertSame(['version' => '1.1'], $jsonApi->transform());
    }

    #[Test]
    public function transformIncludesBothMembers(): void
    {
        $jsonApi = new JsonApiObject('1.1', ['abc' => 'def']);

        self::assertSame(
            ['version' => '1.1', 'meta' => ['abc' => 'def']],
            $jsonApi->transform(),
        );
    }
}
