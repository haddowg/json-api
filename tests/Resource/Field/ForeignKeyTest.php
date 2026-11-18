<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AbstractRelationBuilder;
use haddowg\JsonApi\Resource\Field\AbstractRelationValue;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\ForeignKey;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasManyBuilder;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\HasOneBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Serializer\RelationForeignKeyValidator;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `HasMany` or `HasOne` declared with a foreign key: the name it carries, how it
 * resolves against the related type, the parent key it compares, and the warm-up check.
 */
#[CoversClass(ForeignKey::class)]
#[CoversClass(RelationForeignKeyValidator::class)]
#[CoversClass(AbstractRelationBuilder::class)]
#[CoversClass(AbstractRelationValue::class)]
#[CoversClass(HasManyBuilder::class)]
#[CoversClass(HasOneBuilder::class)]
#[CoversClass(BelongsToManyBuilder::class)]
final class ForeignKeyTest extends TestCase
{
    #[Test]
    public function aHasManyAndAHasOneCarryTheForeignKeyTheyAreDeclaredWith(): void
    {
        self::assertSame('author', HasMany::make('notes', 'notes', foreignKey: 'author')->build()->foreignKey());
        self::assertSame('user', HasMany::make('items', 'inbox-items', 'user')->build()->foreignKey());
        self::assertSame('owner', HasOne::make('profile', 'profiles', 'owner')->build()->foreignKey());
    }

    #[Test]
    public function aRelationDeclaredWithoutOneHasNone(): void
    {
        self::assertNull(HasMany::make('notes', 'notes')->build()->foreignKey());
        self::assertNull(HasOne::make('profile', 'profiles')->build()->foreignKey());
        self::assertNull(BelongsTo::make('author', 'users')->build()->foreignKey());
        self::assertNull(BelongsToMany::make('tags', 'tags')->build()->foreignKey());
        self::assertNull(ForeignKey::nameOf(BelongsTo::make('author', 'users')->build()));
    }

    #[Test]
    public function aBelongsToManyRefusesAForeignKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Relation "tags" is a BelongsToMany, whose members live in its pivot rows, so it takes no foreign key.');

        BelongsToMany::make('tags', 'tags', 'post');
    }

    #[Test]
    public function anEmptyForeignKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HasMany::make('notes', 'notes', '');
    }

    #[Test]
    public function aForeignKeyResolvesToTheRelatedMembersColumn(): void
    {
        $server = self::server();
        $owners = $server->serializerFor('owners');
        self::assertInstanceOf(AbstractResource::class, $owners);

        $byField = ForeignKey::of(self::relation($owners, 'notes'), $server);
        self::assertNotNull($byField);
        self::assertSame('ownerKey', $byField->name);
        self::assertSame('owner_key', $byField->column);
        self::assertFalse($byField->isRelation());

        $byRelation = ForeignKey::of(self::relation($owners, 'authored'), $server);
        self::assertNotNull($byRelation);
        self::assertSame('author', $byRelation->name);
        self::assertSame('writer', $byRelation->column);
        self::assertTrue($byRelation->isRelation());

        self::assertNull(ForeignKey::of(self::relation($owners, 'plain'), $server));
    }

    #[Test]
    public function theParentKeyIsTheWireIdDecodedThroughTheParentsIdEncoder(): void
    {
        $server = self::server();

        self::assertSame('7', ForeignKey::parentKey($server->serializerFor('owners'), (object) ['id' => '7']));
        self::assertSame('cba', $server->serializerFor('encoded-owners')->getId((object) ['id' => 'abc']));
        self::assertSame('abc', ForeignKey::parentKey($server->serializerFor('encoded-owners'), (object) ['id' => 'abc']));
    }

    #[Test]
    public function theValidatorAcceptsAStoredFieldAndAToOneToTheParentsType(): void
    {
        $server = self::server();

        (new RelationForeignKeyValidator($server))->validate('owners', $server->serializerFor('owners'));

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badForeignKeys(): iterable
    {
        yield 'missing' => ['missing', 'it declares no such field or relation'];
        yield 'computed' => ['computedKey', 'it is computed, not stored'];
        yield 'to-many' => ['readers', 'it is a to-many relation'];
        yield 'another type' => ['editor', 'it relates to "editors", not to "bad-owners"'];
        yield 'flattened' => ['flattened', 'it is flattened from another relation with on()'];
        yield 'id' => ['id', 'it is the related type\'s id'];
    }

    #[Test]
    #[DataProvider('badForeignKeys')]
    public function theValidatorRejectsAForeignKeyThatHoldsNoParentId(string $foreignKey, string $problem): void
    {
        BadForeignKeyOwnerResource::$foreignKey = $foreignKey;
        $server = self::server()->register(BadForeignKeyOwnerResource::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "notes" on resource type "bad-owners" reads through the foreign key "' . $foreignKey . '" on type "fk-notes", but ' . $problem);

        (new RelationForeignKeyValidator($server))->validate('bad-owners', $server->serializerFor('bad-owners'));
    }

    private static function relation(AbstractResource $resource, string $name): HasMany
    {
        $relation = $resource->relationNamed($name);
        self::assertInstanceOf(HasMany::class, $relation);

        return $relation;
    }

    private static function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->register(ForeignKeyNoteResource::class)
            ->register(ForeignKeyOwnerResource::class)
            ->register(EncodedForeignKeyOwnerResource::class)
            ->withPsr17($psr17, $psr17);
    }
}

final class ForeignKeyNoteResource extends AbstractResource
{
    public static string $type = 'fk-notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('ownerKey')->storedAs('owner_key'),
            Str::make('computedKey')->computed(),
            BelongsTo::make('author', 'owners')->storedAs('writer'),
            BelongsTo::make('editor', 'editors'),
            HasMany::make('readers', 'owners'),
            Str::make('flattened')->on('author'),
        ];
    }
}

final class ForeignKeyOwnerResource extends AbstractResource
{
    public static string $type = 'owners';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('notes', 'fk-notes', 'ownerKey'),
            HasMany::make('authored', 'fk-notes', foreignKey: 'author'),
            HasOne::make('latestNote', 'fk-notes', 'author'),
            HasMany::make('plain', 'fk-notes'),
        ];
    }
}

final class EncodedForeignKeyOwnerResource extends AbstractResource
{
    public static string $type = 'encoded-owners';

    public function fields(): array
    {
        return [Id::make()->encodeUsing(new ReversingIdEncoder())];
    }
}

final class BadForeignKeyOwnerResource extends AbstractResource
{
    public static string $type = 'bad-owners';

    public static string $foreignKey = 'missing';

    public function fields(): array
    {
        return [Id::make(), HasMany::make('notes', 'fk-notes', self::$foreignKey)];
    }
}
