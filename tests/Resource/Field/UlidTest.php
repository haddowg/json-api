<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Ulid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ulid::class)]
final class UlidTest extends TestCase
{
    #[Test]
    public function generatesA26CharacterCrockfordBase32String(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, \strlen($ulid));
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $ulid);
    }

    #[Test]
    public function matchesTheIdFieldUlidRoutePattern(): void
    {
        self::assertMatchesRegularExpression(
            '/^' . Id::ULID_FORMAT_PATTERN . '$/',
            Ulid::generate(),
        );
    }

    #[Test]
    public function isUnique(): void
    {
        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[Ulid::generate()] = true;
        }

        self::assertCount(1000, $values);
    }

    #[Test]
    public function encodesACanonicalTimestampPrefix(): void
    {
        // The first 10 Crockford-base32 characters encode the 48-bit millisecond
        // timestamp, so a freshly minted ULID's prefix decodes to "about now".
        $before = (int) (\microtime(true) * 1000);
        $ulid = Ulid::generate();
        $after = (int) (\microtime(true) * 1000);

        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        /** @var array<string, int> $map */
        $map = \array_flip(\str_split($alphabet));

        $timestamp = 0;
        for ($i = 0; $i < 10; $i++) {
            $timestamp = ($timestamp << 5) | $map[$ulid[$i]];
        }

        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }
}
