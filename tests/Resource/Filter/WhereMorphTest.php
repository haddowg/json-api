<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\MorphSelection;
use haddowg\JsonApi\Resource\Filter\MorphTarget;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\WhereMorph;
use haddowg\JsonApi\Resource\Filter\WhereMorphBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WhereMorph::class)]
#[CoversClass(WhereMorphBuilder::class)]
#[CoversClass(MorphTarget::class)]
#[CoversClass(MorphSelection::class)]
#[CoversClass(ThroughPathResolver::class)]
#[CoversClass(ArrayFilterHandler::class)]
final class WhereMorphTest extends TestCase
{
    #[Test]
    public function aTypeAloneSelectsEveryTargetOfIt(): void
    {
        self::assertEquals(new MorphSelection('rfps'), $this->filter()->selection(['type' => 'rfps']));
    }

    #[Test]
    public function idsAreReadInEitherFormAndDecoded(): void
    {
        self::assertEquals(new MorphSelection('rfps', ['812', '813']), $this->filter()->selection(['type' => 'rfps', 'id' => '812, 813']));
        self::assertEquals(new MorphSelection('rfps', ['812']), $this->filter()->selection(['type' => 'rfps', 'id' => ['812']]));
        self::assertEquals(new MorphSelection('campaigns', [9]), $this->filter()->selection(['type' => 'campaigns', 'id' => 'c-9']));
    }

    #[Test]
    public function eachTypeValidatesAgainstItsOwnIdFormat(): void
    {
        $filter = $this->filter();

        self::assertEquals([new Pattern('^' . Id::NUMERIC_FORMAT_PATTERN . '$', documentsAs: 'integer')], $filter->idConstraints('rfps'));
        self::assertEquals([new UuidFormat()], $filter->idConstraints('templates'));
        self::assertSame([], $filter->idConstraints('campaigns'));
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'a string' => ['rfps', 'subject', 'Send filter[subject][type]'];
        yield 'no type' => [['id' => '1'], 'subject][type', 'is required'];
        yield 'an empty type' => [['type' => ''], 'subject][type', 'is required'];
        yield 'two types' => [['type' => ['rfps', 'campaigns']], 'subject][type', 'is required'];
        yield 'an undeclared type' => [['type' => 'leads'], 'subject][type', '"leads" is not a type filter[subject] matches. Use one of: rfps, campaigns, templates.'];
        yield 'an unknown member' => [['type' => 'rfps', 'rfps' => '812'], 'subject', 'takes only the members type and id'];
        yield 'a named id' => [['type' => 'rfps', 'id' => ['x' => '1']], 'subject][id', 'Send the ids'];
        yield 'an empty id' => [['type' => 'rfps', 'id' => '1,'], 'subject][id', 'non-empty'];
        yield 'an undecodable id' => [['type' => 'campaigns', 'id' => '9'], 'subject][id', '"9" is not the id of a "campaigns" resource.'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function aMalformedValueIsA400(mixed $value, string $key, string $message): void
    {
        try {
            $this->filter()->selection($value);
            self::fail('Expected FilterValueInvalid.');
        } catch (FilterValueInvalid $e) {
            self::assertSame($key, $e->filterKey);
            self::assertStringContainsString($message, \implode(' ', $e->messages));
        }
    }

    #[Test]
    public function anUnresolvedFilterCannotReadItsValue(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not been resolved');

        WhereMorph::make('subject')->build()->selection(['type' => 'rfps']);
    }

    #[Test]
    public function aRelationTheTypeDoesNotDeclareFailsResolution(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('names the relation "about", which the type it filters does not declare');

        $this->resolver()->resolve(WhereMorph::make('about')->build(), self::fields());
    }

    #[Test]
    public function aMonomorphicRelationFailsResolution(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which is not polymorphic');

        $this->resolver()->resolve(WhereMorph::make('owner')->build(), self::fields());
    }

    #[Test]
    public function aPathIsRefusedAtBuild(): void
    {
        $this->expectException(\LogicException::class);

        WhereMorph::make('subject', 'thread.subject')->build();
    }

    #[Test]
    public function theInMemoryHandlerComparesAMorphTosColumns(): void
    {
        $rows = [
            ['id' => 1, 'subjectType' => 'rfps', 'subjectId' => 812],
            ['id' => 2, 'subjectType' => 'rfps', 'subjectId' => 813],
            ['id' => 3, 'subjectType' => 'campaigns', 'subjectId' => 812],
            ['id' => 4, 'subjectType' => null, 'subjectId' => null],
        ];
        $handler = new ArrayFilterHandler();
        $ids = fn(mixed $value): array => \array_column($handler->apply($this->filter(), $rows, $value), 'id');

        self::assertSame([1, 2], $ids(['type' => 'rfps']));
        self::assertSame([1], $ids(['type' => 'rfps', 'id' => '812']));
        self::assertSame([1, 2], $ids(['type' => 'rfps', 'id' => ['812', '813']]));
        self::assertSame([3], $ids(['type' => 'campaigns', 'id' => 'c-812']));
    }

    #[Test]
    public function theInMemoryHandlerLooksForAMorphToManysMember(): void
    {
        $fields = [
            Id::make()->build(),
            MorphToMany::make('tags', ['rfps', 'campaigns'])
                ->through('TagLink')
                ->identifiedByRows(owner: 'item', type: 'tagType', id: 'tagId')
                ->build(),
        ];
        $filter = $this->resolver()->resolve(WhereMorph::make('tags')->build(), $fields);
        $rows = [
            ['id' => 1, 'tags' => [['tagType' => 'rfps', 'tagId' => 5], ['tagType' => 'campaigns', 'tagId' => 6]]],
            ['id' => 2, 'tags' => [['tagType' => 'rfps', 'tagId' => 7]]],
            ['id' => 3, 'tags' => []],
        ];
        $handler = new ArrayFilterHandler();
        $ids = static fn(mixed $value): array => \array_column($handler->apply($filter, $rows, $value), 'id');

        self::assertSame([1, 2], $ids(['type' => 'rfps']));
        self::assertSame([2], $ids(['type' => 'rfps', 'id' => '7']));
        self::assertSame([1], $ids(['type' => 'campaigns', 'id' => 'c-6']));
    }

    #[Test]
    public function aClosureIdentifiedMorphToIsRefusedInMemory(): void
    {
        $fields = [MorphTo::make('subject', ['rfps'])->identifiedBy(static fn(): ?array => null)->build()];
        $filter = $this->resolver()->resolve(WhereMorph::make('subject')->build(), $fields);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identifiedByColumns()');

        (new ArrayFilterHandler())->apply($filter, [['id' => 1]], ['type' => 'rfps']);
    }

    private function filter(): WhereMorph
    {
        $filter = $this->resolver()->resolve(WhereMorph::make('subject')->build(), self::fields());
        self::assertInstanceOf(WhereMorph::class, $filter);

        return $filter;
    }

    /**
     * @return list<FieldInterface>
     */
    private static function fields(): array
    {
        return [
            Id::make()->build(),
            BelongsTo::make('owner', 'rfps')->build(),
            MorphTo::make('subject', ['rfps', 'campaigns', 'templates'])->identifiedByColumns('subjectType', 'subjectId')->build(),
        ];
    }

    private function resolver(): ThroughPathResolver
    {
        $encoder = new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                return 'c-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
            }

            public function decode(string $wireId): mixed
            {
                return \str_starts_with($wireId, 'c-') ? (int) \substr($wireId, 2) : null;
            }
        };

        return new ThroughPathResolver(static fn(string $type): ?array => match ($type) {
            'rfps' => [Id::make()->numeric()->build()],
            'campaigns' => [Id::make()->encodeUsing($encoder)->build()],
            'templates' => [Id::make()->uuid()->build()],
            default => null,
        });
    }
}
