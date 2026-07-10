<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\Id;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Id::class)]
final class IdTest extends TestCase
{
    #[Test]
    public function forbidsClientIdsByDefault(): void
    {
        $id = Id::make()->build();

        self::assertFalse($id->allowsClientId());
        self::assertFalse($id->requiresClientId());
    }

    #[Test]
    public function allowClientIdMakesAClientIdOptional(): void
    {
        $id = Id::make()->allowClientId()->build();

        self::assertTrue($id->allowsClientId());
        self::assertFalse($id->requiresClientId());
    }

    #[Test]
    public function requireClientIdMakesAClientIdMandatory(): void
    {
        $id = Id::make()->requireClientId()->build();

        self::assertTrue($id->allowsClientId());
        self::assertTrue($id->requiresClientId());
    }

    #[Test]
    public function storeProvidedIsTheDefaultFallback(): void
    {
        self::assertNull(Id::make()->build()->generateIdValue());
    }

    #[Test]
    public function generatedUuidMintsAVersion4Uuid(): void
    {
        $value = Id::make()->uuid()->generated()->build()->generateIdValue();

        self::assertIsString($value);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value,
        );
    }

    #[Test]
    public function generatedUuidMintsAFreshValueEachCall(): void
    {
        $id = Id::make()->uuid()->generated()->build();

        self::assertNotSame($id->generateIdValue(), $id->generateIdValue());
    }

    #[Test]
    public function generatedUlidMintsACrockfordBase32Ulid(): void
    {
        $value = Id::make()->ulid()->generated()->build()->generateIdValue();

        self::assertIsString($value);
        self::assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value);
    }

    #[Test]
    public function generateUsingReturnsTheClosureValue(): void
    {
        $value = Id::make()->generateUsing(static fn(): string => 'minted')->build()->generateIdValue();

        self::assertSame('minted', $value);
    }

    #[Test]
    public function generateUsingSupersedesAFormatGenerator(): void
    {
        $value = Id::make()->uuid()->generated()->generateUsing(static fn(): string => 'override')->build()->generateIdValue();

        self::assertSame('override', $value);
    }

    #[Test]
    public function generatedOnNumericIsAConfigError(): void
    {
        $this->expectException(\LogicException::class);

        Id::make()->numeric()->generated();
    }

    #[Test]
    public function generatedOnAPatternIsAConfigError(): void
    {
        $this->expectException(\LogicException::class);

        Id::make()->pattern('^[a-z]+$')->generated();
    }

    #[Test]
    public function generatedWithoutAFormatIsAConfigError(): void
    {
        $this->expectException(\LogicException::class);

        Id::make()->generated();
    }
}
