<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Serializer;

use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolymorphicSerializer::class)]
final class PolymorphicSerializerTest extends TestCase
{
    #[Test]
    public function delegatesEachObjectToItsResolvedSerializer(): void
    {
        $notes = new StubSerializer('notes');
        $images = new StubSerializer('images');

        $serializer = new PolymorphicSerializer(static function (mixed $object) use ($notes, $images): SerializerInterface {
            self::assertIsArray($object);

            return $object['kind'] === 'notes' ? $notes : $images;
        });

        $note = ['kind' => 'notes', 'id' => '7'];
        $image = ['kind' => 'images', 'id' => '9'];
        $request = new StubJsonApiRequest();

        // type / id / attributes each delegate to the serializer resolved for that
        // very object, so a heterogeneous collection renders per member.
        self::assertSame('notes', $serializer->getType($note));
        self::assertSame('7', $serializer->getId($note));
        self::assertSame([], $serializer->getAttributes($note, $request));

        self::assertSame('images', $serializer->getType($image));
        self::assertSame('9', $serializer->getId($image));
        self::assertSame([], $serializer->getAttributes($image, $request));
    }
}
