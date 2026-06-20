<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Field\Uuid;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Accessor::class)]
#[CoversClass(ArrayHash::class)]
#[CoversClass(ArrayList::class)]
#[CoversClass(Boolean::class)]
#[CoversClass(Date::class)]
#[CoversClass(DateTime::class)]
#[CoversClass(Decimal::class)]
#[CoversClass(Email::class)]
#[CoversClass(Id::class)]
#[CoversClass(Integer::class)]
#[CoversClass(Ip::class)]
#[CoversClass(Map::class)]
#[CoversClass(Slug::class)]
#[CoversClass(Str::class)]
#[CoversClass(Time::class)]
#[CoversClass(Url::class)]
#[CoversClass(Uuid::class)]
#[CoversClass(\haddowg\JsonApi\Resource\Field\AbstractField::class)]
final class FieldTest extends TestCase
{
    #[Test]
    public function makeSetsNameAndDefaultColumn(): void
    {
        $field = Str::make('title');

        self::assertSame('title', $field->name());
        self::assertSame('title', $field->column());
    }

    #[Test]
    public function storedAsOverridesColumn(): void
    {
        $field = Str::make('title')->storedAs('post_title');

        self::assertSame('title', $field->name());
        self::assertSame('post_title', $field->column());
    }

    #[Test]
    public function defaultFlags(): void
    {
        $field = Str::make('title');

        self::assertFalse($field->isReadOnly(true));
        self::assertFalse($field->isReadOnly(false));
        self::assertFalse($field->isHidden());
        self::assertTrue($field->isSparseField());
        self::assertFalse($field->isSortable());
        self::assertSame([], $field->constraints());
    }

    #[Test]
    public function readOnlyAppliesToBothContexts(): void
    {
        $field = Str::make('slug')->readOnly();

        self::assertTrue($field->isReadOnly(true));
        self::assertTrue($field->isReadOnly(false));
    }

    #[Test]
    public function readOnlyOnCreateAndUpdateAreContextScoped(): void
    {
        self::assertTrue(Str::make('a')->readOnlyOnCreate()->isReadOnly(true));
        self::assertFalse(Str::make('a')->readOnlyOnCreate()->isReadOnly(false));
        self::assertFalse(Str::make('a')->readOnlyOnUpdate()->isReadOnly(true));
        self::assertTrue(Str::make('a')->readOnlyOnUpdate()->isReadOnly(false));
    }

    #[Test]
    public function writeOnlyDefaultsFalseAndIsTheInverseOfReadOnly(): void
    {
        self::assertFalse(Str::make('a')->isWriteOnly());
        self::assertTrue(Str::make('password')->writeOnly()->isWriteOnly());

        // Write-only is not read-only: it is still hydrated on both contexts.
        $field = Str::make('password')->writeOnly();
        self::assertFalse($field->isReadOnly(true));
        self::assertFalse($field->isReadOnly(false));
    }

    #[Test]
    public function writeOnlyAfterReadOnlyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be both write-only and read-only');

        Str::make('secret')->readOnly()->writeOnly();
    }

    #[Test]
    public function readOnlyAfterWriteOnlyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be both read-only and write-only');

        Str::make('secret')->writeOnly()->readOnly();
    }

    #[Test]
    public function flagBuilders(): void
    {
        self::assertTrue(Str::make('a')->hidden()->isHidden());
        self::assertFalse(Str::make('a')->notSparseField()->isSparseField());
        self::assertTrue(Str::make('a')->sortable()->isSortable());
    }

    #[Test]
    public function requiredAppendsConstraintWithAlwaysContext(): void
    {
        $constraints = Str::make('title')->required()->constraints();

        self::assertCount(1, $constraints);
        self::assertInstanceOf(Required::class, $constraints[0]);
        self::assertTrue($constraints[0]->context()->onCreate);
        self::assertTrue($constraints[0]->context()->onUpdate);
    }

    #[Test]
    public function requiredOnCreateAndUpdateScopeContext(): void
    {
        $create = Str::make('a')->requiredOnCreate()->constraints()[0];
        self::assertTrue($create->context()->appliesTo(true));
        self::assertFalse($create->context()->appliesTo(false));

        $update = Str::make('a')->requiredOnUpdate()->constraints()[0];
        self::assertFalse($update->context()->appliesTo(true));
        self::assertTrue($update->context()->appliesTo(false));
    }

    #[Test]
    public function onCreateBuilderScopesNestedConstraints(): void
    {
        $field = Str::make('title')->onCreate(static function (Str $f): void {
            $f->minLength(1)->maxLength(10);
        });

        $constraints = $field->constraints();
        self::assertCount(2, $constraints);
        foreach ($constraints as $constraint) {
            self::assertTrue($constraint->context()->appliesTo(true));
            self::assertFalse($constraint->context()->appliesTo(false));
        }
    }

    #[Test]
    public function onUpdateBuilderScopesNestedConstraints(): void
    {
        $field = Str::make('title')->onUpdate(static function (Str $f): void {
            $f->maxLength(5);
        });

        $constraint = $field->constraints()[0];
        self::assertFalse($constraint->context()->appliesTo(true));
        self::assertTrue($constraint->context()->appliesTo(false));
    }

    #[Test]
    public function whenFoldsBuilderConstraintsIntoASingleConditional(): void
    {
        $condition = static fn(mixed $value): bool => $value !== null;
        $field = Str::make('title')->when($condition, static function (Str $f): void {
            $f->minLength(3)->maxLength(10);
        });

        $constraints = $field->constraints();
        self::assertCount(1, $constraints);

        $when = $constraints[0];
        self::assertInstanceOf(When::class, $when);
        self::assertSame($condition, $when->condition);

        $wrapped = \array_map(
            static fn(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $c): string => $c::class,
            $when->constraints,
        );
        self::assertSame([MinLength::class, MaxLength::class], $wrapped);
    }

    #[Test]
    public function whenScopesTheConditionalToTheActiveContext(): void
    {
        $field = Str::make('title')->onCreate(static function (Str $f): void {
            $f->when(static fn(mixed $v): bool => true, static function (Str $g): void {
                $g->minLength(3);
            });
        });

        $constraint = $field->constraints()[0];
        self::assertInstanceOf(When::class, $constraint);
        self::assertTrue($constraint->context()->appliesTo(true));
        self::assertFalse($constraint->context()->appliesTo(false));
    }

    #[Test]
    public function nestedWhenBuildersCompose(): void
    {
        $field = Str::make('title')->when(static fn(mixed $v): bool => true, static function (Str $f): void {
            $f->minLength(1)->when(static fn(mixed $v): bool => true, static function (Str $g): void {
                $g->maxLength(5);
            });
        });

        $constraints = $field->constraints();
        self::assertCount(1, $constraints);

        $outer = $constraints[0];
        self::assertInstanceOf(When::class, $outer);

        self::assertCount(2, $outer->constraints);
        self::assertInstanceOf(MinLength::class, $outer->constraints[0]);
        self::assertInstanceOf(When::class, $outer->constraints[1]);
    }

    #[Test]
    public function stringFluentConstraints(): void
    {
        $field = Str::make('a')->minLength(1)->maxLength(10)->pattern('^x');
        $types = \array_map(static fn(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $c): string => $c::class, $field->constraints());

        self::assertSame([MinLength::class, MaxLength::class, \haddowg\JsonApi\Resource\Constraint\Pattern::class], $types);
    }

    #[Test]
    public function stringFormatShortcutsMatchDedicatedFields(): void
    {
        self::assertEquals(
            Str::make('c')->email()->constraints(),
            Email::make('c')->constraints(),
        );
        self::assertInstanceOf(EmailFormat::class, Str::make('c')->email()->constraints()[0]);
        self::assertInstanceOf(UrlFormat::class, Url::make('c')->constraints()[0]);
        self::assertInstanceOf(UuidFormat::class, Uuid::make('c')->constraints()[0]);
        self::assertInstanceOf(SlugFormat::class, Slug::make('c')->constraints()[0]);
        self::assertInstanceOf(IpFormat::class, Ip::make('c')->constraints()[0]);
    }

    #[Test]
    public function emailStrictnessIsTypedOnTheConstraint(): void
    {
        $loose = Str::make('c')->email()->constraints()[0];
        self::assertInstanceOf(EmailFormat::class, $loose);
        self::assertFalse($loose->strict);

        $strict = Str::make('c')->email(true)->constraints()[0];
        self::assertInstanceOf(EmailFormat::class, $strict);
        self::assertTrue($strict->strict);

        // Email::strict() reconciles to a SINGLE strict EmailFormat, not a second rule.
        $email = Email::make('c')->strict()->constraints();
        self::assertCount(1, $email);
        self::assertInstanceOf(EmailFormat::class, $email[0]);
        self::assertTrue($email[0]->strict);
    }

    #[Test]
    public function constrainAttachesArbitraryConstraints(): void
    {
        $custom = new class implements \haddowg\JsonApi\Resource\Constraint\ConstraintInterface {
            public function context(): \haddowg\JsonApi\Resource\Constraint\Context
            {
                return new \haddowg\JsonApi\Resource\Constraint\Context();
            }
        };

        $field = Str::make('x')->minLength(2)->constrain($custom);

        $types = \array_map(
            static fn(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $c): string => $c::class,
            $field->constraints(),
        );
        self::assertSame([MinLength::class, $custom::class], $types);
        self::assertSame($custom, $field->constraints()[1]);
    }

    #[Test]
    public function compositionCombinatorsWrapTheirConstraints(): void
    {
        $sequential = Str::make('x')->sequentially(new MinLength(3), new MaxLength(10))->constraints();
        self::assertCount(1, $sequential);
        self::assertInstanceOf(Sequentially::class, $sequential[0]);
        self::assertCount(2, $sequential[0]->constraints);

        $either = Str::make('x')->atLeastOneOf(new MinLength(3), new In(['n/a']))->constraints();
        self::assertCount(1, $either);
        self::assertInstanceOf(AtLeastOneOf::class, $either[0]);
        self::assertCount(2, $either[0]->constraints);
    }

    #[Test]
    public function compareWithAttachesACrossFieldConstraint(): void
    {
        $compare = DateTime::make('endDate')->compareWith('startDate', Comparison::GreaterThan)->constraints()[0];

        self::assertInstanceOf(CompareField::class, $compare);
        self::assertSame('startDate', $compare->field);
        self::assertSame(Comparison::GreaterThan, $compare->operator);
    }

    #[Test]
    public function urlAllowedSchemesNarrowsConstraint(): void
    {
        $constraint = Url::make('c')->allowedSchemes('https')->constraints()[1];

        self::assertInstanceOf(UrlFormat::class, $constraint);
        self::assertSame(['https'], $constraint->allowedSchemes);
    }

    #[Test]
    public function ipVersionShortcuts(): void
    {
        self::assertSame(4, Ip::make('c')->v4()->constraints()[1]->version ?? null);
        self::assertSame(6, Ip::make('c')->v6()->constraints()[1]->version ?? null);
    }

    #[Test]
    public function serializeReadsFromArrayModel(): void
    {
        $field = Str::make('title');

        self::assertSame('Hello', $field->serialize(['title' => 'Hello'], $this->request(), 'title'));
    }

    #[Test]
    public function serializeReadsFromObjectGetter(): void
    {
        $model = new class {
            public function getTitle(): string
            {
                return 'From getter';
            }
        };

        self::assertSame('From getter', Str::make('title')->serialize($model, $this->request(), 'title'));
    }

    #[Test]
    public function serializeUsingOverridesAccessor(): void
    {
        $field = Str::make('title')->serializeUsing(static fn(): string => 'override');

        self::assertSame('override', $field->serialize(['title' => 'ignored'], $this->request(), 'title'));
    }

    #[Test]
    public function computedFieldUsesExtractUsing(): void
    {
        $field = Str::make('preview')->computed()->extractUsing(
            static function (mixed $model): string {
                self::assertIsArray($model);
                $body = $model['body'];
                self::assertIsString($body);

                return \substr($body, 0, 3);
            },
        );

        self::assertNull($field->column());
        self::assertSame('Hel', $field->serialize(['body' => 'Hello world'], $this->request(), 'preview'));
    }

    #[Test]
    public function computedUsingIsComputedReadOnlyAndDerivesItsValue(): void
    {
        $field = Str::make('displayName')->computedUsing(
            static function (mixed $model, mixed $request, string $name): string {
                self::assertIsArray($model);
                self::assertSame('displayName', $name);
                $first = $model['first'];
                $last = $model['last'];
                self::assertIsString($first);
                self::assertIsString($last);

                return $first . ' ' . $last;
            },
        );

        // Sugar = computed() (no backing column) + the value closure + read-only on
        // BOTH create and update.
        self::assertNull($field->column());
        self::assertTrue($field->isReadOnly(true));
        self::assertTrue($field->isReadOnly(false));
        self::assertSame(
            'Ada Lovelace',
            $field->serialize(['first' => 'Ada', 'last' => 'Lovelace'], $this->request(), 'displayName'),
        );
    }

    #[Test]
    public function computedUsingFieldDoesNotHydrate(): void
    {
        $field = Str::make('displayName')->computedUsing(static fn(): string => 'x');
        $model = ['displayName' => 'original'];

        // computed() => no backing column => hydrate is a no-op (and the field is
        // read-only anyway, so the resource never even reaches its hydrate()).
        self::assertSame($model, $field->hydrate($model, 'new', [], $this->request(), true));
    }

    #[Test]
    public function onRecordsTheBackingRelationAndKeepsTheBackingColumn(): void
    {
        $field = Str::make('authorName')->on('author');

        self::assertSame('author', $field->relatedVia());
        // A flattened attribute still has its own backing member on the related model.
        self::assertSame('authorName', $field->column());
    }

    #[Test]
    public function onHonoursStoredAsForTheBackingColumn(): void
    {
        $field = Str::make('authorName')->on('author')->storedAs('name');

        self::assertSame('author', $field->relatedVia());
        self::assertSame('name', $field->column());
    }

    #[Test]
    public function onAcceptsAMultiHopDotPath(): void
    {
        // A multi-hop chain is recorded verbatim; the backing member is still the
        // field's own column() on the FINAL related model.
        $field = Str::make('countryName')->on('publisher.country')->storedAs('name');

        self::assertSame('publisher.country', $field->relatedVia());
        self::assertSame('name', $field->column());
    }

    #[Test]
    public function plainAttributeReportsNoRelatedVia(): void
    {
        self::assertNull(Str::make('title')->relatedVia());
    }

    #[Test]
    public function onThenComputedUsingThrows(): void
    {
        $this->expectException(\LogicException::class);

        Str::make('authorName')->on('author')->computedUsing(static fn(): string => 'x');
    }

    #[Test]
    public function computedUsingThenOnThrows(): void
    {
        $this->expectException(\LogicException::class);

        Str::make('authorName')->computedUsing(static fn(): string => 'x')->on('author');
    }

    #[Test]
    public function onThenExtractUsingThrows(): void
    {
        $this->expectException(\LogicException::class);

        Str::make('authorName')->on('author')->extractUsing(static fn(): string => 'x');
    }

    #[Test]
    public function extractUsingThenOnThrows(): void
    {
        $this->expectException(\LogicException::class);

        Str::make('authorName')->extractUsing(static fn(): string => 'x')->on('author');
    }

    #[Test]
    public function integerCastsOnSerializeAndHydrate(): void
    {
        $field = Integer::make('count');

        self::assertSame(5, $field->serialize(['count' => '5'], $this->request(), 'count'));

        $model = $field->hydrate(['count' => 0], '9', [], $this->request(), true);
        self::assertIsArray($model);
        self::assertSame(9, $model['count']);
    }

    #[Test]
    public function decimalCasts(): void
    {
        $field = Decimal::make('price');

        self::assertSame(1.5, $field->serialize(['price' => '1.5'], $this->request(), 'price'));

        $model = $field->hydrate(['price' => 0], '2', [], $this->request(), true);
        self::assertIsArray($model);
        self::assertSame(2.0, $model['price']);
    }

    #[Test]
    public function booleanCasts(): void
    {
        $field = Boolean::make('active');

        self::assertTrue($field->serialize(['active' => 1], $this->request(), 'active'));

        $model = $field->hydrate(['active' => true], 0, [], $this->request(), true);
        self::assertIsArray($model);
        self::assertFalse($model['active']);
    }

    #[Test]
    public function dateTimeSerializesAndHydrates(): void
    {
        $field = DateTime::make('publishedAt');
        $date = new \DateTimeImmutable('2020-01-02T03:04:05+00:00');

        self::assertSame('2020-01-02T03:04:05+00:00', $field->serialize(['publishedAt' => $date], $this->request(), 'publishedAt'));

        $model = $field->hydrate(['publishedAt' => null], '2021-06-07T08:09:10+00:00', [], $this->request(), true);
        self::assertIsArray($model);
        $hydrated = $model['publishedAt'];
        self::assertInstanceOf(\DateTimeImmutable::class, $hydrated);
        self::assertSame('2021-06-07T08:09:10+00:00', $hydrated->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function dateUsesDateOnlyFormat(): void
    {
        $field = Date::make('birthday');
        $date = new \DateTimeImmutable('2020-01-02T03:04:05+00:00');

        self::assertSame('2020-01-02', $field->serialize(['birthday' => $date], $this->request(), 'birthday'));
    }

    #[Test]
    public function timeUsesTimeOnlyFormat(): void
    {
        $field = Time::make('opensAt');
        $date = new \DateTimeImmutable('2020-01-02T03:04:05+00:00');

        self::assertSame('03:04:05', $field->serialize(['opensAt' => $date], $this->request(), 'opensAt'));
    }

    #[Test]
    public function dateTimeBoundConstraints(): void
    {
        $field = DateTime::make('a')
            ->before(new \DateTimeImmutable('2030-01-01'))
            ->after(static fn(): \DateTimeImmutable => new \DateTimeImmutable('2000-01-01'));

        self::assertInstanceOf(Before::class, $field->constraints()[0]);
        self::assertInstanceOf(After::class, $field->constraints()[1]);
    }

    #[Test]
    public function arrayListSerializesAndConstrains(): void
    {
        $field = ArrayList::make('tags')->minItems(1)->maxItems(5)->uniqueItems()->sorted();

        self::assertSame(['a', 'b', 'c'], $field->serialize(['tags' => ['c', 'a', 'b']], $this->request(), 'tags'));

        $types = \array_map(static fn(\haddowg\JsonApi\Resource\Constraint\ConstraintInterface $c): string => $c::class, $field->constraints());
        self::assertContains(MaxItems::class, $types);
        self::assertContains(UniqueItems::class, $types);
    }

    #[Test]
    public function arrayHashSortsKeys(): void
    {
        $field = ArrayHash::make('meta')->sortKeys();

        self::assertSame(['a' => 1, 'b' => 2], $field->serialize(['meta' => ['b' => 2, 'a' => 1]], $this->request(), 'meta'));
    }

    #[Test]
    public function mapSpreadsChildrenAcrossColumns(): void
    {
        $field = Map::make('address')->fields(
            Str::make('street'),
            Str::make('city'),
        );

        $serialized = $field->serialize(
            ['street' => '1 High St', 'city' => 'London'],
            $this->request(),
            'address',
        );
        self::assertSame(['street' => '1 High St', 'city' => 'London'], $serialized);

        $model = $field->hydrate(
            ['street' => '', 'city' => ''],
            ['street' => '2 Low St', 'city' => 'Leeds'],
            [],
            $this->request(),
            true,
        );
        self::assertIsArray($model);
        self::assertSame('2 Low St', $model['street']);
        self::assertSame('Leeds', $model['city']);
    }

    #[Test]
    public function mapGatesReadOnlyChildrenOnHydrate(): void
    {
        // A read-only child must not be writable through the nested object — the
        // Map gates it the same way the resource gates a top-level field.
        $field = Map::make('settings')->fields(
            Str::make('name'),
            Str::make('role')->readOnly(),
        );

        $model = $field->hydrate(
            ['name' => 'old', 'role' => 'user'],
            ['name' => 'new', 'role' => 'admin'],
            [],
            $this->request(),
            true,
        );

        self::assertIsArray($model);
        self::assertSame('new', $model['name']);
        self::assertSame('user', $model['role'], 'a read-only Map child must not be overwritten');
    }

    #[Test]
    public function mapSkipsWriteOnlyChildrenOnSerialize(): void
    {
        // A write-only child is accepted on write but never rendered — the Map
        // skips it the same way the resource skips a top-level write-only field.
        $field = Map::make('credentials')->fields(
            Str::make('username'),
            Str::make('secret')->writeOnly(),
        );

        $nested = $field->serialize(
            ['username' => 'ada', 'secret' => 's3cr3t'],
            $this->request(),
            'credentials',
        );

        self::assertSame(['username' => 'ada'], $nested);

        // But it is still hydrated through the nested object.
        $model = $field->hydrate(
            ['username' => 'old', 'secret' => 'old'],
            ['username' => 'new', 'secret' => 'new'],
            [],
            $this->request(),
            true,
        );
        self::assertIsArray($model);
        self::assertSame('new', $model['secret'], 'a write-only Map child is still hydrated');
    }

    #[Test]
    public function idDefaultsToIdName(): void
    {
        $field = Id::make();

        self::assertSame('id', $field->name());
        self::assertSame('42', $field->serialize(['id' => 42], $this->request(), 'id'));
    }

    #[Test]
    public function idFormatShortcuts(): void
    {
        self::assertInstanceOf(UuidFormat::class, Id::make()->uuid()->constraints()[0]);
        self::assertInstanceOf(UlidFormat::class, Id::make()->ulid()->constraints()[0]);
        self::assertInstanceOf(\haddowg\JsonApi\Resource\Constraint\Pattern::class, Id::make()->numeric()->constraints()[0]);
    }

    #[Test]
    public function idFormatShortcutsAlsoSetTheRoutePattern(): void
    {
        self::assertNull(Id::make()->routePattern());
        self::assertSame(Id::UUID_FORMAT_PATTERN, Id::make()->uuid()->routePattern());
        self::assertSame(Id::ULID_FORMAT_PATTERN, Id::make()->ulid()->routePattern());
        self::assertSame(Id::NUMERIC_FORMAT_PATTERN, Id::make()->numeric()->routePattern());
    }

    #[Test]
    public function idPatternStripsAnchorsForTheRoutePatternButKeepsTheAnchoredConstraint(): void
    {
        $field = Id::make()->pattern('^[a-z0-9]+$');

        self::assertSame('[a-z0-9]+', $field->routePattern());
        self::assertInstanceOf(\haddowg\JsonApi\Resource\Constraint\Pattern::class, $field->constraints()[0]);
        self::assertSame('^[a-z0-9]+$', $field->constraints()[0]->regex);
    }

    #[Test]
    public function idMatchAsSetsTheRoutePatternWithoutAConstraint(): void
    {
        $field = Id::make()->matchAs('\d+');

        self::assertSame('\d+', $field->routePattern());
        self::assertSame([], $field->constraints());
    }

    #[Test]
    public function idUlidPatternMatchesAValidUlidCaseInsensitively(): void
    {
        $pattern = '/^' . Id::ULID_FORMAT_PATTERN . '$/';

        self::assertSame(1, \preg_match($pattern, '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertSame(1, \preg_match($pattern, \strtolower('01ARZ3NDEKTSV4RRFFQ69G5FAV')));
        self::assertSame(0, \preg_match($pattern, 'not-a-ulid'));
    }

    #[Test]
    public function idEncoderRoundTripsOnSerialize(): void
    {
        $field = Id::make()->encodeUsing(new ReversingIdEncoder());

        self::assertSame($field->encoder(), $field->encoder());
        self::assertNotNull($field->encoder());
        // The domain object holds the storage key; serialize emits the wire id.
        self::assertSame('54321', $field->serializeWithoutRequest(['id' => '12345']));
    }

    #[Test]
    public function idWithoutAnEncoderSerializesTheRawId(): void
    {
        self::assertNull(Id::make()->encoder());
        self::assertSame('42', Id::make()->serializeWithoutRequest(['id' => 42]));
    }

    #[Test]
    public function integerInConstraint(): void
    {
        $field = Integer::make('rank')->in([1, 2, 3]);

        self::assertInstanceOf(In::class, $field->constraints()[0]);
        self::assertSame([1, 2, 3], $field->constraints()[0]->values);
    }

    #[Test]
    public function decimalMaxConstraint(): void
    {
        $field = Decimal::make('price')->max(99.99);

        self::assertInstanceOf(Max::class, $field->constraints()[0]);
        self::assertSame(99.99, $field->constraints()[0]->value);
    }

    #[Test]
    public function hydrateRespectsFillUsing(): void
    {
        $field = Str::make('name')->fillUsing(
            static function (mixed $model, mixed $value): array {
                self::assertIsArray($model);
                self::assertIsString($value);
                $model['name'] = \strtoupper($value);

                return $model;
            },
        );

        $model = $field->hydrate(['name' => ''], 'bob', [], $this->request(), true);
        self::assertIsArray($model);
        self::assertSame('BOB', $model['name']);
    }

    #[Test]
    public function hydrateUsesDeserializeUsing(): void
    {
        $field = Str::make('name')->deserializeUsing(static function (mixed $v): string {
            self::assertIsString($v);

            return \trim($v);
        });

        $model = $field->hydrate(['name' => ''], '  bob  ', [], $this->request(), true);
        self::assertIsArray($model);
        self::assertSame('bob', $model['name']);
    }

    #[Test]
    public function computedFieldWithoutFillHookDoesNotHydrate(): void
    {
        $field = Str::make('preview')->computed();
        $model = ['preview' => 'original'];

        self::assertSame($model, $field->hydrate($model, 'new', [], $this->request(), true));
    }

    private function request(): StubJsonApiRequest
    {
        return new StubJsonApiRequest();
    }
}
