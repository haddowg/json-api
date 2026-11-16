<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\MorphColumns;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `MorphTo` declared with `identifiedByColumns()` reads its stored pair from two
 * declared fields of the parent, as an `identifiedBy()` closure would.
 */
#[CoversClass(MorphToBuilder::class)]
#[CoversClass(MorphTo::class)]
#[CoversClass(MorphColumns::class)]
final class MorphToIdentifiedByColumnsTest extends TestCase
{
    #[Test]
    public function thePairIsReadFromTheTwoFields(): void
    {
        $relation = MorphTo::make('subject', ['rfps', 'campaigns'])->identifiedByColumns(type: 'subjectType', id: 'subjectId')->build();

        self::assertTrue($relation->storesIdentifiers());
        self::assertSame(['rfps', 812], $relation->storedIdentifier(self::item('rfps', 812), self::request()));
        self::assertNull($relation->storedIdentifier(self::item(null, null), self::request()));
        self::assertNull($relation->storedIdentifier(self::item('rfps', null), self::request()));
        self::assertEquals(new MorphColumns('subjectType', 'subjectId', ['rfps', 'campaigns']), $relation->identifierColumns());
    }

    #[Test]
    public function aTypeMapNamesTheStoredValues(): void
    {
        $relation = MorphTo::make('subject', ['rfps', 'campaigns'])
            ->identifiedByColumns('subjectType', 'subjectId', ['App\\Rfp' => 'rfps', 'App\\Campaign' => 'campaigns'])
            ->build();

        self::assertSame(['campaigns', '9'], $relation->storedIdentifier(self::item('App\\Campaign', '9'), self::request()));
        self::assertSame(['App\\Rfp'], $relation->identifierColumns()?->storedValues('rfps'));
    }

    #[Test]
    public function aStoredTypeNamingNoDeclaredTypeIsALogicException(): void
    {
        $relation = MorphTo::make('subject', ['rfps'])->identifiedByColumns('subjectType', 'subjectId')->build();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"leads"');

        $relation->storedIdentifier(self::item('leads', 1), self::request());
    }

    #[Test]
    public function aClosureIdentifiedRelationHasNoColumns(): void
    {
        $relation = MorphTo::make('subject', ['rfps'])->identifiedBy(static fn(): ?array => null)->build();

        self::assertNull($relation->identifierColumns());
    }

    #[Test]
    public function itCannotBeDeclaredWithIdentifiedBy(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('both identifiedBy() and identifiedByColumns()');

        MorphTo::make('subject', ['rfps'])
            ->identifiedBy(static fn(): ?array => null)
            ->identifiedByColumns('subjectType', 'subjectId')
            ->build();
    }

    #[Test]
    public function itCannotBeDeclaredWithIdentifierMeta(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identifiedByColumns() with identifierMeta()');

        MorphTo::make('subject', ['rfps'])
            ->identifiedByColumns('subjectType', 'subjectId')
            ->identifierMeta(static fn(): array => [])
            ->build();
    }

    #[Test]
    public function aFieldMustBeAFieldOfTheParent(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not a field name');

        MorphTo::make('subject', ['rfps'])->identifiedByColumns('subject.type', 'subjectId')->build();
    }

    #[Test]
    public function theTwoFieldsMustDiffer(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('same field twice');

        MorphTo::make('subject', ['rfps'])->identifiedByColumns('subject', 'subject')->build();
    }

    #[Test]
    public function aTypeMapMustMapToADeclaredType(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('maps the stored type "App\\Lead" to "leads"');

        MorphTo::make('subject', ['rfps'])->identifiedByColumns('subjectType', 'subjectId', ['App\\Lead' => 'leads'])->build();
    }

    private static function item(?string $type, string|int|null $id): object
    {
        $item = new \stdClass();
        $item->subjectType = $type;
        $item->subjectId = $id;

        return $item;
    }

    private static function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/'));
    }
}
