<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\OpenApi\TypeNoun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeNoun::class)]
final class TypeNounTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function typeNames(): iterable
    {
        yield 'regular plural' => ['advertisers', 'advertiser', 'advertisers'];
        yield 'y plural' => ['agencies', 'agency', 'agencies'];
        yield 'irregular plural' => ['people', 'person', 'people'];
        yield 'es plural' => ['statuses', 'status', 'statuses'];
        yield 'already singular' => ['healthcheck', 'healthcheck', 'healthcheck'];
        yield 'hyphenated' => ['export-jobs', 'export job', 'export jobs'];
        yield 'camelCase' => ['publicProfiles', 'public profile', 'public profiles'];
        yield 'snake_case' => ['catalog_exports', 'catalog export', 'catalog exports'];
        yield 'leading acronym' => ['APIKeys', 'API key', 'API keys'];
        yield 'uncountable' => ['news', 'news', 'news'];
    }

    #[Test]
    #[DataProvider('typeNames')]
    public function aTypeNameReadsAsWordsInTheSingularAndPlural(string $type, string $singular, string $plural): void
    {
        $noun = TypeNoun::fromType($type);

        self::assertSame($singular, $noun->singular);
        self::assertSame($plural, $noun->plural);
    }

    #[Test]
    public function aDeclaredSingularIsUsedAsWritten(): void
    {
        $noun = TypeNoun::fromType('media', 'media item');

        self::assertSame('media item', $noun->singular);
        self::assertSame('media', $noun->plural);
        self::assertSame('a media item', $noun->withArticle());
    }

    #[Test]
    public function aBlankDeclaredSingularFallsBackToTheInflection(): void
    {
        self::assertSame('agency', TypeNoun::fromType('agencies', '  ')->singular);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function articles(): iterable
    {
        yield 'consonant' => ['tag', 'a'];
        yield 'vowel' => ['advertiser', 'an'];
        yield 'vowel u' => ['update', 'an'];
        yield 'u with a y sound' => ['user', 'a'];
        yield 'uni with a y sound' => ['unique code', 'a'];
        yield 'un- prefix' => ['uninstall', 'an'];
        yield 'eu' => ['euro price', 'a'];
        yield 'one' => ['one-off charge', 'a'];
        yield 'silent h' => ['hour', 'an'];
        yield 'sounded h' => ['healthcheck', 'a'];
        yield 'acronym with a vowel letter name' => ['SDK release', 'an'];
        yield 'acronym with a consonant letter name' => ['URL alias', 'a'];
    }

    #[Test]
    #[DataProvider('articles')]
    public function theArticleFollowsTheFirstSound(string $noun, string $article): void
    {
        self::assertSame($article, TypeNoun::article($noun));
    }
}
