<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Serializer\PolymorphicSerializer;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use haddowg\JsonApi\Tests\Double\StubSerializer;
use haddowg\JsonApi\Tests\Double\StubSerializerResolver;
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

    #[Test]
    public function forwardsDeclaredLinksFromAMemberThatRendersThem(): void
    {
        $files = new PolymorphicDeclaredLinkResource();
        $notes = new StubSerializer('notes');

        $serializer = new PolymorphicSerializer(static fn(mixed $object): SerializerInterface => $object === 'file' ? $files : $notes);
        $request = new StubJsonApiRequest();

        self::assertSame(['download'], \array_keys($serializer->renderDeclaredLinks('file', $request)));
        self::assertSame([], $serializer->renderDeclaredLinks('note', $request));
    }

    #[Test]
    public function aClosureOnlySerializerCountsNothing(): void
    {
        $serializer = new PolymorphicSerializer(static fn(mixed $object): SerializerInterface => new StubSerializer('notes'));

        self::assertSame([], $serializer->members());
        self::assertSame([], $serializer->getCountableRelationships([]));
    }

    #[Test]
    public function itCountsTheUnionOfItsMemberTypesCountableRelationships(): void
    {
        $serializer = new PolymorphicSerializer(
            static fn(mixed $object): SerializerInterface => new StubSerializer('notes'),
            [new PolymorphicCountableResource(), new PolymorphicOtherCountableResource(), new StubSerializer('notes')],
        );

        self::assertSame(['comments', 'tags', 'likes'], $serializer->getCountableRelationships([]));
    }

    #[Test]
    public function forRelationTakesEachRegisteredRelatedTypeAsAMember(): void
    {
        $files = new PolymorphicCountableResource();
        $resolver = (new StubSerializerResolver('notes'))->withSerializer('files', $files);
        $relation = MorphToMany::make('attachments', ['files', 'notes', 'unregistered'])->build();

        $serializer = PolymorphicSerializer::forRelation($relation, $resolver);

        self::assertSame([$files, $resolver->serializerFor('notes')], $serializer->members());
        self::assertSame(['comments', 'tags'], $serializer->getCountableRelationships([]));
        self::assertSame('files', $serializer->getType('anything'));
    }
}

final class PolymorphicCountableResource extends AbstractResource
{
    public static string $type = 'files';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('comments', 'notes')->countable(),
            HasMany::make('tags', 'notes')->countable(),
            HasMany::make('owners', 'notes'),
            BelongsTo::make('folder', 'notes'),
        ];
    }
}

final class PolymorphicOtherCountableResource extends AbstractResource
{
    public static string $type = 'images';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('tags', 'notes')->countable(),
            HasMany::make('likes', 'notes')->countable(),
        ];
    }
}

final class PolymorphicDeclaredLinkResource extends AbstractResource
{
    public static string $type = 'files';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function links(): array
    {
        return [DeclaredLink::make('download')->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/download')];
    }
}
