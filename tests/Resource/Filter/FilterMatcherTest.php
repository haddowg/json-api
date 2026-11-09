<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Filter;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterDirective;
use haddowg\JsonApi\Resource\Filter\FilterFamily;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\FilterMatcher;
use haddowg\JsonApi\Resource\Filter\ListValue;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FilterMatcher::class)]
#[CoversClass(FilterFamily::class)]
#[CoversClass(ListValue::class)]
final class FilterMatcherTest extends TestCase
{
    #[Test]
    public function anExactKeyMatchesItsFilter(): void
    {
        $title = Where::make('title')->build();

        self::assertSame($title, FilterMatcher::match([Where::make('rating')->build(), $title], 'title'));
        self::assertNull(FilterMatcher::match([$title], 'rating'));
    }

    #[Test]
    public function aFamilyResolvesTheSuffixWithTheRequest(): void
    {
        $request = $this->request();
        $seen = [];
        $family = FilterFamily::make('value', static function (string $suffix, JsonApiRequestInterface $r) use (&$seen): FilterInterface {
            $seen[] = [$suffix, $r];

            return Where::make('value.' . $suffix, $suffix)->build();
        });

        $filter = FilterMatcher::match([$family], 'value.a.b', $request);

        self::assertInstanceOf(Where::class, $filter);
        self::assertSame('value.a.b', $filter->key());
        self::assertSame('a.b', $filter->column);
        self::assertSame([['a.b', $request]], $seen);
    }

    #[Test]
    public function anExactKeyWinsOverAFamilyPrefix(): void
    {
        $exact = Where::make('value.cap', 'cap_column')->build();
        $family = FilterFamily::make('value', static fn(string $suffix): never => self::fail('The family must not resolve a key an exact filter answers.'));

        self::assertSame($exact, FilterMatcher::match([$family, $exact], 'value.cap', $this->request()));
    }

    #[Test]
    public function aFamilyNeverMatchesItsBarePrefix(): void
    {
        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => Where::make('value.' . $suffix)->build());

        self::assertNull(FilterMatcher::match([$family], 'value', $this->request()));
        self::assertNull(FilterMatcher::match([$family], 'value.', $this->request()));
        self::assertNull(FilterMatcher::match([$family], 'values.cap', $this->request()));
    }

    #[Test]
    public function theLongestPrefixDecidesAndItsNullIsFinal(): void
    {
        $short = FilterFamily::make('value', static fn(string $suffix): FilterInterface => Where::make('value.' . $suffix, 'short')->build());
        $long = FilterFamily::make('value.meta', static fn(string $suffix): ?FilterInterface => $suffix === 'known' ? Where::make('value.meta.known', 'long')->build() : null);

        $known = FilterMatcher::match([$short, $long], 'value.meta.known', $this->request());
        self::assertInstanceOf(Where::class, $known);
        self::assertSame('long', $known->column);

        self::assertNull(FilterMatcher::match([$short, $long], 'value.meta.unknown', $this->request()));

        $other = FilterMatcher::match([$short, $long], 'value.cap', $this->request());
        self::assertInstanceOf(Where::class, $other);
        self::assertSame('short', $other->column);
    }

    #[Test]
    public function aSuffixPatternRejectsBeforeTheResolverRuns(): void
    {
        $family = FilterFamily::make('value', static fn(string $suffix): never => self::fail('The resolver must not run for a rejected suffix.'))
            ->withSuffixPattern('[a-z_]+');

        self::assertNull(FilterMatcher::match([$family], 'value.Cap', $this->request()));
    }

    #[Test]
    public function anInvalidSuffixPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FilterFamily::make('value', static fn(string $suffix): ?FilterInterface => null)->withSuffixPattern('[');
    }

    #[Test]
    public function aPrefixWithABracketIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FilterFamily::make('value[', static fn(string $suffix): ?FilterInterface => null);
    }

    #[Test]
    public function aFamilyNeedsTheRequestToResolve(): void
    {
        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => Where::make('value.' . $suffix)->build());

        self::assertNotNull(FilterMatcher::match([$family, Where::make('title')->build()], 'title'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Filter family "value" needs the request');

        FilterMatcher::match([$family], 'value.cap');
    }

    #[Test]
    public function aResolvedFilterMustCarryTheFullKey(): void
    {
        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => Where::make($suffix)->build());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Filter family "value" resolved "value.cap" to a filter keyed "cap"');

        FilterMatcher::match([$family], 'value.cap', $this->request());
    }

    #[Test]
    public function aListFamilyMustResolveAFilterAcceptingTheSameList(): void
    {
        $matching = FilterFamily::make('value', static fn(string $suffix): FilterInterface => WhereIn::make('value.' . $suffix)->acceptsList(delimited: false)->build())
            ->acceptsList(delimited: false);
        self::assertInstanceOf(WhereIn::class, FilterMatcher::match([$matching], 'value.cap', $this->request()));

        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => WhereIn::make('value.' . $suffix)->acceptsList()->build())
            ->acceptsList(delimited: false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Filter family "value" resolved "value.cap" to a filter that accepts a delimited list');

        FilterMatcher::match([$family], 'value.cap', $this->request());
    }

    #[Test]
    public function aScalarFamilyMustNotResolveAListFilter(): void
    {
        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => WhereIn::make('value.' . $suffix)->acceptsList()->build());

        $this->expectException(\LogicException::class);

        FilterMatcher::match([$family], 'value.cap', $this->request());
    }

    #[Test]
    public function directivesFollowTheRequestAndRejectAnUnknownKey(): void
    {
        $status = Where::make('status')->build();
        $family = FilterFamily::make('value', static fn(string $suffix): FilterInterface => Where::make('value.' . $suffix, $suffix)->build());

        $directives = FilterMatcher::directives(['value.cap' => 'x', 'status' => 'open'], [$status, $family], $this->request());

        self::assertCount(2, $directives);
        self::assertSame('value.cap', $directives[0]->key());
        self::assertSame('x', $directives[0]->value);
        self::assertEquals(new FilterDirective($status, 'open'), $directives[1]);

        try {
            FilterMatcher::directives(['value' => 'x'], [$status, $family], $this->request());
            self::fail('A bare family prefix must be unrecognized.');
        } catch (FilterParamUnrecognized $e) {
            self::assertSame('value', $e->filterParam);
        }

        $this->expectException(FilterParamUnrecognized::class);
        FilterMatcher::directives(['value.nope' => 'x'], [FilterFamily::make('value', static fn(string $suffix): ?FilterInterface => null)], $this->request());
    }

    #[Test]
    public function aDelimitedListTakesTheArrayAndTheCommaForms(): void
    {
        $filter = WhereIn::make('columns')->acceptsList()->build();

        self::assertSame(['a', 'b'], FilterMatcher::directives(['columns' => ['a', 'b']], [$filter])[0]->value);
        self::assertSame(['a', 'b'], FilterMatcher::directives(['columns' => 'a, b'], [$filter])[0]->value);
        self::assertSame(['a,b', 'c'], FilterMatcher::directives(['columns' => ['a,b', 'c']], [$filter])[0]->value);
    }

    #[Test]
    public function anUndelimitedListKeepsCommasAndRefusesAString(): void
    {
        $filter = WhereIn::make('columns')->acceptsList(delimited: false)->build();

        self::assertSame(['a,b', 'c'], FilterMatcher::directives(['columns' => ['a,b', 'c']], [$filter])[0]->value);

        $this->expectException(FilterValueInvalid::class);
        FilterMatcher::directives(['columns' => 'a,b'], [$filter]);
    }

    #[Test]
    public function aListMemberMustBeASingleValue(): void
    {
        $filter = WhereIn::make('columns')->acceptsList()->build();

        $this->expectException(FilterValueInvalid::class);
        FilterMatcher::directives(['columns' => [['a']]], [$filter]);
    }

    #[Test]
    public function aListMayNotBeKeyedByName(): void
    {
        $filter = WhereIn::make('columns')->acceptsList()->build();

        $this->expectException(FilterValueInvalid::class);
        FilterMatcher::directives(['columns' => ['x' => 'a']], [$filter]);
    }

    #[Test]
    public function aFilterWithoutAListKeepsItsValueAsRequested(): void
    {
        $filter = WhereIn::make('ids')->build();

        self::assertNull($filter->listValue());
        self::assertSame('1,2', FilterMatcher::directives(['ids' => '1,2'], [$filter])[0]->value);
    }

    #[Test]
    public function aDelimitedListUsesTheFiltersDelimiter(): void
    {
        $filter = WhereIn::make('regions')->delimiter('|')->acceptsList()->build();

        self::assertEquals(ListValue::delimited('|'), $filter->listValue());
        self::assertSame(['a,b', 'c'], FilterMatcher::directives(['regions' => 'a,b|c'], [$filter])[0]->value);
    }

    #[Test]
    public function mergeKeepsAFamilyBesideAnExactFilterOfTheSameKey(): void
    {
        $exact = Where::make('value')->build();
        $family = FilterFamily::make('value', static fn(string $suffix): ?FilterInterface => null);
        $replacement = Where::make('value', 'other')->build();

        self::assertSame([$replacement, $family], FilterMatcher::merge([$exact], [$family, $replacement]));
    }

    private function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/campaigns'));
    }
}
