<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphMany;
use haddowg\JsonApi\Resource\Field\MorphOne;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Serializer\RelationForeignKeyValidator;
use haddowg\JsonApi\Server\Server;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `MorphMany` and `MorphOne` build the `HasMany` and `HasOne` a foreign key naming a
 * `MorphTo` gives, and warm-up refuses them unless that member is a `MorphTo`.
 */
#[CoversClass(MorphMany::class)]
#[CoversClass(MorphOne::class)]
#[CoversClass(RelationForeignKeyValidator::class)]
final class MorphManyTest extends TestCase
{
    #[Test]
    public function theyBuildAHasManyAndAHasOneReadThroughTheMorphTo(): void
    {
        $many = MorphMany::make('notes', 'mm-notes', 'owner')->build();
        $one = MorphOne::make('latestNote', 'mm-notes', 'owner')->build();

        self::assertInstanceOf(HasMany::class, $many);
        self::assertSame('owner', $many->foreignKey());
        self::assertTrue($many->foreignKeyIsMorph());
        self::assertInstanceOf(HasOne::class, $one);
        self::assertSame('owner', $one->foreignKey());
        self::assertTrue($one->foreignKeyIsMorph());
        self::assertFalse(HasMany::make('notes', 'mm-notes', 'owner')->build()->foreignKeyIsMorph());
    }

    #[Test]
    public function warmUpAcceptsAMorphTo(): void
    {
        MorphManyOwnerResource::$foreignKey = 'owner';
        $server = self::server();

        (new RelationForeignKeyValidator($server))->validate('mm-owners', $server->serializerFor('mm-owners'));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function warmUpRefusesAMemberThatIsNoMorphTo(): void
    {
        MorphManyOwnerResource::$foreignKey = 'title';
        $server = self::server();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "notes" on resource type "mm-owners" reads through the foreign key "title" on type "mm-notes", but it is not a MorphTo, which a MorphMany or MorphOne must name');

        (new RelationForeignKeyValidator($server))->validate('mm-owners', $server->serializerFor('mm-owners'));
    }

    private static function server(): Server
    {
        $psr17 = new Psr17Factory();

        return Server::make()
            ->register(MorphManyNoteResource::class)
            ->register(MorphManyOwnerResource::class)
            ->withPsr17($psr17, $psr17);
    }
}

final class MorphManyNoteResource extends AbstractResource
{
    public static string $type = 'mm-notes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            MorphTo::make('owner', ['mm-owners'])->identifiedByColumns('ownerType', 'ownerId'),
        ];
    }
}

final class MorphManyOwnerResource extends AbstractResource
{
    public static string $type = 'mm-owners';

    public static string $foreignKey = 'owner';

    public function fields(): array
    {
        return [Id::make(), MorphMany::make('notes', 'mm-notes', self::$foreignKey), MorphOne::make('latestNote', 'mm-notes', 'owner')];
    }
}
