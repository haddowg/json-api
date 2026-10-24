<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityTag::class)]
final class EntityTagTest extends TestCase
{
    #[Test]
    public function aHashIsTheXxh128OfTheBytes(): void
    {
        self::assertSame(\hash('xxh128', '{}'), EntityTag::hash('{}'));
    }

    #[Test]
    public function aKeyedHashCombinesTheArtifactHashWithEveryKeyInOrder(): void
    {
        $keys = [['App\HostDecorator', 'example.com'], ['App\FeatureDecorator', ['beta' => true]]];

        self::assertSame(
            EntityTag::hash("abc\n" . '[["App\\\\HostDecorator","example.com"],["App\\\\FeatureDecorator",{"beta":true}]]'),
            EntityTag::keyed('abc', $keys),
        );
        self::assertNotSame(EntityTag::keyed('abc', $keys), EntityTag::keyed('abc', \array_reverse($keys)));
        self::assertNotSame(EntityTag::keyed('abc', $keys), EntityTag::keyed('abd', $keys));
        self::assertNotSame(EntityTag::keyed('abc', $keys), EntityTag::keyed('abc', [['App\Other', 'example.com'], $keys[1]]));
    }

    /**
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function ifNoneMatch(): iterable
    {
        yield 'no header' => ['"abc"', null, false];
        yield 'empty header' => ['"abc"', '', false];
        yield 'strong match' => ['"abc"', '"abc"', true];
        yield 'weak match' => ['"abc"', 'W/"abc"', true];
        yield 'weak tag against a strong candidate' => ['W/"abc"', '"abc"', true];
        yield 'bare tag' => ['abc', '"abc"', true];
        yield 'one of a list' => ['"abc"', '"x", W/"abc" ,"y"', true];
        yield 'wildcard' => ['"abc"', '*', true];
        yield 'mismatch' => ['"abc"', '"abd", W/"ab"', false];
        yield 'a comma inside a quoted tag' => ['"a,b"', '"a,b"', true];
        yield 'a quoted tag split on its comma' => ['"a"', '"a,b"', false];
    }

    #[Test]
    #[DataProvider('ifNoneMatch')]
    public function ifNoneMatchIsComparedWeakly(string $etag, ?string $header, bool $matches): void
    {
        self::assertSame($matches, EntityTag::matches($etag, $header));
    }
}
