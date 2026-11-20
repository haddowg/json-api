<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field\Internal;

use haddowg\JsonApi\Resource\Field\Internal\PatternCharacters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatternCharacters::class)]
final class PatternCharactersTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function patternsMatchingAFullStop(): iterable
    {
        foreach (['.+', '[a-z.]+', '[^,]+', '\w+\.\w+', '\S+', '\x2e', '\x{2E}', '[[:punct:]]+', '\p{P}+', '\pP', '\Qa.b\E', 'a|.', '\W', '\D+', '[\x2e]', '\056', '(?:a|b.)', '[^a-z]', '(?!x).+'] as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function patternsMatchingNoFullStop(): iterable
    {
        foreach (['[a-z]+', '[^.,]+', 'lat|lng', '[a-z][a-z0-9_]*', '\d+', '(?=[a-z])\w+', '(?![.])[a-z]+', '(?<=a.)[a-z]', '[\w-]+', '\*', '(?#a.b)[a-z]', '\Qab\E', '[a-z\]]+', '(?<n>[a-z])\k<n>', '\w+', '[]a]'] as $pattern) {
            yield $pattern => [$pattern];
        }
    }

    #[Test]
    public function aQuantifierIsNotTheCharacterItSpells(): void
    {
        self::assertFalse(PatternCharacters::canMatch('[a-z]*', '*'));
        self::assertFalse(PatternCharacters::canMatch('a+b?', '+'));
        self::assertTrue(PatternCharacters::canMatch('a\*', '*'));
        self::assertTrue(PatternCharacters::canMatch('[*]', '*'));
        self::assertTrue(PatternCharacters::canMatch('a,b', ','));
        self::assertTrue(PatternCharacters::canMatch('[^a-z]+', ','));
        self::assertFalse(PatternCharacters::canMatch('[^.,*]+', ','));
        self::assertFalse(PatternCharacters::canMatch('[^.,*]+', '*'));
    }

    #[Test]
    #[DataProvider('patternsMatchingAFullStop')]
    public function aPatternWithAnAtomMatchingTheCharacterCanMatchIt(string $pattern): void
    {
        self::assertTrue(PatternCharacters::canMatch($pattern, '.'));
    }

    #[Test]
    #[DataProvider('patternsMatchingNoFullStop')]
    public function aPatternWhoseAtomsAllRefuseTheCharacterCannotMatchIt(string $pattern): void
    {
        self::assertFalse(PatternCharacters::canMatch($pattern, '.'));
    }
}
