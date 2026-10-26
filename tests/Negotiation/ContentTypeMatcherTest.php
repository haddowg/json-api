<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Negotiation\ContentTypeMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentTypeMatcher::class)]
final class ContentTypeMatcherTest extends TestCase
{
    #[Test]
    public function theMostSpecificAcceptedEntryWins(): void
    {
        $match = ContentTypeMatcher::match('image/png', ['*/*', 'image/*', 'image/png']);

        self::assertNotNull($match);
        self::assertSame('image/png', $match->mediaType);
        self::assertSame('image/png', $match->accepted);
        self::assertTrue($match->isExplicit());
        self::assertFalse($match->corsSafelisted);
    }

    #[Test]
    public function aWildcardMatchIsNotExplicit(): void
    {
        $match = ContentTypeMatcher::match('Text/Plain; charset=utf-8', ['TEXT/*']);

        self::assertNotNull($match);
        self::assertSame('text/plain', $match->mediaType);
        self::assertSame('text/*', $match->accepted);
        self::assertFalse($match->isExplicit());
        self::assertTrue($match->corsSafelisted);
    }

    #[Test]
    public function acceptedParametersAreIgnored(): void
    {
        self::assertNotNull(ContentTypeMatcher::match('text/csv', ['text/csv; header=present']));
    }

    #[Test]
    public function nothingMatchesAnAbsentOrMalformedHeader(): void
    {
        self::assertNull(ContentTypeMatcher::match('', ['*/*']));
        self::assertNull(ContentTypeMatcher::match('csv', ['*/*']));
        self::assertNull(ContentTypeMatcher::match('text/csv, text/plain', ['*/*']));
        self::assertNull(ContentTypeMatcher::match('text/csv', []));
    }

    #[Test]
    public function theThreeFormMediaTypesAreSafelisted(): void
    {
        self::assertTrue(ContentTypeMatcher::isCorsSafelisted('text/plain;charset=UTF-8'));
        self::assertTrue(ContentTypeMatcher::isCorsSafelisted('multipart/form-data; boundary=x'));
        self::assertTrue(ContentTypeMatcher::isCorsSafelisted('Application/X-WWW-Form-Urlencoded'));
        self::assertFalse(ContentTypeMatcher::isCorsSafelisted('application/json'));
        self::assertFalse(ContentTypeMatcher::isCorsSafelisted('text/csv'));
    }
}
