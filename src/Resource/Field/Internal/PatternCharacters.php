<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field\Internal;

/**
 * Reads a regular expression one atom at a time to tell whether any string it matches can
 * hold a given character. Each escape, character class and `.` is compiled on its own and
 * tried against the character, so the answer follows PCRE rather than a list of escapes.
 * What a lookaround asserts consumes nothing and is left out. The answer leans to yes: a
 * pattern whose lookahead rules the character out, such as `(?!.*\.).+`, still counts,
 * since `.+` alone can match it.
 *
 * @internal
 */
final class PatternCharacters
{
    private const string DELIMITER = "\x01";

    public static function canMatch(string $pattern, string $character): bool
    {
        $length = \strlen($pattern);
        $groups = [];
        $lookarounds = 0;
        $i = 0;
        while ($i < $length) {
            $char = $pattern[$i];
            if ($char === '\\' && \substr($pattern, $i, 2) === '\\Q') {
                $end = \strpos($pattern, '\\E', $i + 2);
                $literal = \substr($pattern, $i + 2, $end === false ? null : $end - $i - 2);
                if ($lookarounds === 0 && \str_contains($literal, $character)) {
                    return true;
                }
                $i = $end === false ? $length : $end + 2;

                continue;
            }
            if ($char === '\\' || $char === '[') {
                $atom = $char === '\\' ? self::escapeAt($pattern, $i) : self::classAt($pattern, $i);
                if ($lookarounds === 0 && self::atomMatches($atom, $character)) {
                    return true;
                }
                $i += \strlen($atom);

                continue;
            }
            if ($char === '(') {
                if (\substr($pattern, $i, 3) === '(?#') {
                    $end = \strpos($pattern, ')', $i);
                    $i = $end === false ? $length : $end + 1;

                    continue;
                }
                $lookaround = \preg_match('/\G\((?:\?<?[=!]|\*(?:n?la|n?lb|(?:positive|negative)_look(?:ahead|behind))\b)/', $pattern, $match, 0, $i) === 1;
                $groups[] = $lookaround;
                $lookarounds += $lookaround ? 1 : 0;
                ++$i;

                continue;
            }
            if ($char === ')') {
                $lookarounds -= \array_pop($groups) === true ? 1 : 0;
                ++$i;

                continue;
            }
            if ($lookarounds === 0 && ($char === '.' || $char === $character)) {
                return true;
            }
            ++$i;
        }

        return false;
    }

    /**
     * The escape sequence starting at `$i`: a backslash, its letter, and the braces, hex or
     * octal digits, or control character that belong to it.
     */
    private static function escapeAt(string $pattern, int $i): string
    {
        $patterns = [
            '/\G\\\\[a-zA-Z]\{[^}]*\}/',
            '/\G\\\\x[0-9a-fA-F]{0,2}/',
            '/\G\\\\[0-9]{1,3}/',
            '/\G\\\\[pP][a-zA-Z]/',
            '/\G\\\\c./s',
            '/\G\\\\[kg]<[^>]*>/',
            "/\\G\\\\[kg]'[^']*'/",
            '/\G\\\\./su',
        ];
        foreach ($patterns as $escape) {
            if (\preg_match($escape, $pattern, $match, 0, $i) === 1) {
                return $match[0];
            }
        }

        return '\\';
    }

    /**
     * The character class starting at `$i`, through its closing bracket.
     */
    private static function classAt(string $pattern, int $i): string
    {
        $length = \strlen($pattern);
        $j = $i + 1;
        if ($j < $length && $pattern[$j] === '^') {
            ++$j;
        }
        if ($j < $length && $pattern[$j] === ']') {
            ++$j;
        }
        while ($j < $length && $pattern[$j] !== ']') {
            if ($pattern[$j] === '\\') {
                $j += \strlen(self::escapeAt($pattern, $j));

                continue;
            }
            if (\substr($pattern, $j, 2) === '[:') {
                $end = \strpos($pattern, ':]', $j + 2);
                $j = $end === false ? $j + 1 : $end + 2;

                continue;
            }
            ++$j;
        }

        return \substr($pattern, $i, $j - $i + 1);
    }

    private static function atomMatches(string $atom, string $character): bool
    {
        return @\preg_match(self::DELIMITER . '^(?:' . $atom . ')$' . self::DELIMITER . 'u', $character) === 1;
    }
}
