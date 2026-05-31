<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\AbstractSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractSerializer::class)]
final class AbstractSerializerTest extends TestCase
{
    #[Test]
    public function formatsDateTimeHelper(): void
    {
        $serializer = new HelperSerializer();
        $dateTime = new \DateTimeImmutable('2026-05-31T12:34:56+00:00');

        self::assertSame('2026-05-31T12:34:56+00:00', $serializer->iso8601($dateTime));
    }

    #[Test]
    public function formatsDecimalHelper(): void
    {
        $serializer = new HelperSerializer();

        self::assertSame(3.14, $serializer->decimal(3.14159, 2));
        self::assertSame(5.0, $serializer->decimal('5'));
    }
}

/**
 * Minimal concrete {@see AbstractSerializer} exposing the
 * {@see \haddowg\JsonApi\Transformer\TransformerTrait} helpers it adds, so they
 * can be exercised directly. The serializer is stateless: identity depends only
 * on the object and the request-shaped members receive the request as a
 * parameter.
 */
final class HelperSerializer extends AbstractSerializer
{
    public function getType(mixed $object): string
    {
        return 'test';
    }

    public function getId(mixed $object): string
    {
        return '1';
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return null;
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return [];
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return [];
    }

    public function iso8601(\DateTimeInterface $dateTime): string
    {
        return $this->toIso8601DateTime($dateTime);
    }

    public function decimal(mixed $value, int $precision = 12): float
    {
        return $this->toDecimal($value, $precision);
    }
}
