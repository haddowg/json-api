<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Sort;

use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortFamily;
use haddowg\JsonApi\Resource\Sort\SortFamilyInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SortMatcher::class)]
#[CoversClass(SortFamily::class)]
final class SortMatcherTest extends TestCase
{
    #[Test]
    public function anExactKeyMatchesItsSort(): void
    {
        $title = SortByField::make('title');

        self::assertSame($title, SortMatcher::match([SortByField::make('rating'), $title], 'title'));
        self::assertNull(SortMatcher::match([$title], 'rating'));
    }

    #[Test]
    public function aFamilyResolvesTheSuffixAfterItsPrefix(): void
    {
        $seen = [];
        $family = SortFamily::make('value', static function (string $suffix, JsonApiRequestInterface $request) use (&$seen): SortInterface {
            $seen[] = $suffix;

            return SortByField::make('value.' . $suffix, $suffix);
        });

        $sort = SortMatcher::match([$family], 'value.cap', $this->request());

        self::assertInstanceOf(SortByField::class, $sort);
        self::assertSame('value.cap', $sort->key());
        self::assertSame('cap', $sort->column);
        self::assertSame(['cap'], $seen);
    }

    #[Test]
    public function aSuffixMayCarryDots(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix, $suffix));

        $sort = SortMatcher::match([$family], 'value.a.b', $this->request());

        self::assertInstanceOf(SortByField::class, $sort);
        self::assertSame('a.b', $sort->column);
    }

    #[Test]
    public function anExactKeyWinsOverAFamilyPrefix(): void
    {
        $exact = SortByField::make('value.cap', 'cap_column');
        $family = SortFamily::make('value', static fn(string $suffix): never => self::fail('The family must not resolve a key an exact sort answers.'));

        self::assertSame($exact, SortMatcher::match([$family, $exact], 'value.cap', $this->request()));
    }

    #[Test]
    public function aFamilyNeverMatchesItsBarePrefix(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix));

        self::assertNull(SortMatcher::match([$family], 'value', $this->request()));
        self::assertNull(SortMatcher::match([$family], 'value.', $this->request()));
        self::assertNull(SortMatcher::match([$family], 'values.cap', $this->request()));

        // An exact sort may share the prefix as its own key.
        $exact = SortByField::make('value');
        self::assertSame($exact, SortMatcher::match([$family, $exact], 'value', $this->request()));
    }

    #[Test]
    public function aResolverReturningNullLeavesTheKeyUnrecognized(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): ?SortInterface => null);

        self::assertNull(SortMatcher::match([$family], 'value.unknown', $this->request()));

        try {
            SortMatcher::directives(['-value.unknown'], [$family], $this->request());
            self::fail('An unresolved family key must be unrecognized.');
        } catch (SortParamUnrecognized $e) {
            self::assertSame('value.unknown', $e->sortParam);
        }
    }

    #[Test]
    public function aResolvedSortMustCarryTheFullRequestedKey(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make($suffix));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sort family "value" resolved "value.cap" to a sort keyed "cap"');

        SortMatcher::match([$family], 'value.cap', $this->request());
    }

    #[Test]
    public function theSuffixPatternRejectsBeforeTheResolverRuns(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix))
            ->withSuffixPattern('[a-z_]+');

        self::assertSame('[a-z_]+', $family->suffixPattern());
        self::assertNotNull(SortMatcher::match([$family], 'value.end_date', $this->request()));
        self::assertNull(SortMatcher::match([$family], 'value.End', $this->request()));
        self::assertNull(SortMatcher::match([$family], 'value.a.b', $this->request()));
    }

    #[Test]
    public function theDefaultSuffixPatternAcceptsAnyNonEmptySuffixWithoutAComma(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix));

        self::assertSame(SortFamilyInterface::DEFAULT_SUFFIX_PATTERN, $family->suffixPattern());
        self::assertNotNull(SortMatcher::match([$family], 'value.Any-thing 1', $this->request()));
        self::assertNull(SortMatcher::match([$family], 'value.a,b', $this->request()));
    }

    #[Test]
    public function theLongestMatchingPrefixDecides(): void
    {
        $short = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix, 'short'));
        $long = SortFamily::make('value.meta', static fn(string $suffix): ?SortInterface => $suffix === 'known' ? SortByField::make('value.meta.' . $suffix, 'long') : null);

        $known = SortMatcher::match([$short, $long], 'value.meta.known', $this->request());
        self::assertInstanceOf(SortByField::class, $known);
        self::assertSame('long', $known->column);

        // The most specific family declined, so the key is unrecognized rather than
        // falling through to the shorter prefix.
        self::assertNull(SortMatcher::match([$short, $long], 'value.meta.unknown', $this->request()));

        $other = SortMatcher::match([$short, $long], 'value.cap', $this->request());
        self::assertInstanceOf(SortByField::class, $other);
        self::assertSame('short', $other->column);
    }

    #[Test]
    public function aFamilyNeedsTheRequestToResolve(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix));

        // An exact key still matches without a request.
        self::assertNotNull(SortMatcher::match([$family, SortByField::make('title')], 'title'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Sort family "value" needs the request');

        SortMatcher::match([$family], 'value.cap');
    }

    #[Test]
    public function theResolverReceivesTheRequest(): void
    {
        $request = $this->request();
        $received = null;
        $family = SortFamily::make('value', static function (string $suffix, JsonApiRequestInterface $r) use (&$received): SortInterface {
            $received = $r;

            return SortByField::make('value.' . $suffix);
        });

        SortMatcher::match([$family], 'value.cap', $request);

        self::assertSame($request, $received);
    }

    #[Test]
    public function directivesCarryTheDirectionInRequestedOrder(): void
    {
        $family = SortFamily::make('value', static fn(string $suffix): SortInterface => SortByField::make('value.' . $suffix, $suffix));
        $title = SortByField::make('title');

        $directives = SortMatcher::directives(['-value.cap', 'title'], [$title, $family], $this->request());

        self::assertCount(2, $directives);
        self::assertSame('value.cap', $directives[0]->sort->key());
        self::assertTrue($directives[0]->descending);
        self::assertEquals(new SortDirective($title, false), $directives[1]);
    }

    #[Test]
    public function directivesRejectSortingWithNoVocabulary(): void
    {
        self::assertSame([], SortMatcher::directives([], []));

        $this->expectException(SortingUnsupported::class);

        SortMatcher::directives(['title'], []);
    }

    #[Test]
    public function mergeKeepsAFamilyBesideAnExactSortSharingItsKey(): void
    {
        $exact = SortByField::make('value');
        $family = SortFamily::make('value', static fn(string $suffix): ?SortInterface => null);
        $override = SortByField::make('value', 'other');

        self::assertSame([$override, $family], SortMatcher::merge([$exact, $family], [$override]));
    }

    #[Test]
    public function anInvalidFamilyDeclarationIsRefused(): void
    {
        foreach (['', 'a,b', '-value'] as $prefix) {
            try {
                SortFamily::make($prefix, static fn(string $suffix): ?SortInterface => null);
                self::fail(\sprintf('The prefix "%s" must be refused.', $prefix));
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);

        SortFamily::make('value', static fn(string $suffix): ?SortInterface => null)->withSuffixPattern('[a-z');
    }

    private function request(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/campaigns'));
    }
}
