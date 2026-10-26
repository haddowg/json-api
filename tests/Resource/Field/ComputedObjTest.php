<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\OpenApi\RepresentationContext;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An `Obj` whose value comes from `computedUsing()` or `extractUsing()` still runs its
 * declared children over that value, so each child renders the way it does from a
 * stored column.
 */
#[CoversClass(Obj::class)]
final class ComputedObjTest extends TestCase
{
    private const string AT = '2024-07-01T12:30:00+00:00';

    #[Test]
    public function aComputedObjFormatsADateTimeChild(): void
    {
        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen'), Integer::make('visits'))
            ->computedUsing(static fn(mixed $model): array => [
                'lastSeen' => new \DateTimeImmutable(self::AT),
                'visits' => 3,
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['lastSeen' => self::AT, 'visits' => 3], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function anExtractedObjFormatsADateTimeChild(): void
    {
        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen'))
            ->extractUsing(static fn(mixed $model): array => ['lastSeen' => new \DateTimeImmutable(self::AT)])
            ->build();

        self::assertSame(['lastSeen' => self::AT], $this->render($field));
    }

    #[Test]
    public function aComputedObjectValueFormatsItsChildren(): void
    {
        $value = new \stdClass();
        $value->lastSeen = new \DateTimeImmutable(self::AT);

        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen'))
            ->computedUsing(static fn(mixed $model): object => $value)
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['lastSeen' => self::AT], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aChildReadsItsOwnColumnFromTheComputedValue(): void
    {
        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen')->storedAs('last_seen'))
            ->computedUsing(static fn(mixed $model): array => ['last_seen' => new \DateTimeImmutable(self::AT)])
            ->build();

        self::assertSame(['lastSeen' => self::AT], $this->render($field));
    }

    #[Test]
    public function aNestedObjFormatsItsChildren(): void
    {
        $field = Obj::make('activity')
            ->fields(Obj::make('latest')->fields(DateTime::make('at'), Str::make('kind')))
            ->computedUsing(static fn(mixed $model): array => [
                'latest' => ['at' => new \DateTimeImmutable(self::AT), 'kind' => 'login'],
            ])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['latest' => ['at' => self::AT, 'kind' => 'login']], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function aListChildIsRenderedAsAList(): void
    {
        $field = Obj::make('stats')
            ->fields(ArrayList::make('tags')->sorted())
            ->computedUsing(static fn(mixed $model): array => ['tags' => [3 => 'b', 7 => 'a']])
            ->build();

        $rendered = $this->render($field);

        self::assertSame(['tags' => ['a', 'b']], $rendered);
        $this->assertMatchesReadSchema($field, $rendered);
    }

    #[Test]
    public function anUndeclaredKeyPassesThroughAndAnAbsentChildStaysAbsent(): void
    {
        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen'), Integer::make('visits'))
            ->computedUsing(static fn(mixed $model): array => ['source' => 'import', 'lastSeen' => new \DateTimeImmutable(self::AT)])
            ->build();

        self::assertSame(['source' => 'import', 'lastSeen' => self::AT], $this->render($field));
    }

    #[Test]
    public function aHiddenOrWriteOnlyChildIsDropped(): void
    {
        $field = Obj::make('stats')
            ->fields(Str::make('name'), Str::make('secret')->hidden(), Str::make('token')->writeOnly())
            ->computedUsing(static fn(mixed $model): array => ['name' => 'n', 'secret' => 's', 'token' => 't'])
            ->build();

        self::assertSame(['name' => 'n'], $this->render($field));
    }

    #[Test]
    public function anEmptyOrNullComputedValueKeepsItsShape(): void
    {
        $empty = Obj::make('stats')->fields(Str::make('name'))->computedUsing(static fn(mixed $model): array => [])->build();
        $null = Obj::make('stats')->fields(Str::make('name'))->computedUsing(static fn(mixed $model): ?array => null)->build();

        self::assertEquals(new \stdClass(), $this->render($empty));
        self::assertNull($this->render($null));
    }

    #[Test]
    public function aSerializeHookStillOwnsTheWholeValue(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $field = Obj::make('stats')
            ->fields(DateTime::make('lastSeen'))
            ->serializeUsing(static fn(mixed $model, JsonApiRequestInterface $request, string $name): array => ['lastSeen' => $at])
            ->build();

        self::assertSame(['lastSeen' => $at], $this->render($field));
    }

    private function render(FieldInterface $field): mixed
    {
        return $field->serialize([], new StubJsonApiRequest(), $field->name());
    }

    private function assertMatchesReadSchema(FieldInterface $field, mixed $rendered): void
    {
        $attributes = (new SchemaProjector())->projectAttributes([$field], RepresentationContext::Read)->toArray();
        self::assertIsArray($attributes['properties'] ?? null);
        $schema = \json_encode($attributes['properties'][$field->name()], \JSON_THROW_ON_ERROR);

        $result = (new Validator())->validate(\json_decode(\json_encode($rendered, \JSON_THROW_ON_ERROR)), $schema);

        self::assertTrue($result->isValid(), $result->error()?->message() ?? '');
    }
}
