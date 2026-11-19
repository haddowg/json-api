<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher;

/**
 * Which keys of an object attribute a client may select one by one with
 * `fields[TYPE]=<attr>.<key>`: those the pattern matches and the predicate accepts. A
 * declaration with only a predicate documents every key as selectable. A key never holds
 * a `.`, which separates the keys of a path into nested objects.
 */
final readonly class SelectableKeys
{
    /**
     * The schema pattern of a key when only a predicate decides: anything up to the next
     * comma, since `fields[TYPE]` is a comma-separated list, or the next full stop, which
     * starts a key of a nested object.
     */
    public const string ANY_KEY_PATTERN = '[^.,]+';

    /**
     * @param (\Closure(string, JsonApiRequestInterface): bool)|null $accepts
     */
    public function __construct(
        public ?\Closure $accepts = null,
        public ?string $pattern = null,
    ) {}

    public function accepts(string $key, JsonApiRequestInterface $request): bool
    {
        if ($key === '') {
            return false;
        }
        if ($this->pattern !== null && \preg_match(KeyFamilyMatcher::suffixRegex($this->pattern), $key) !== 1) {
            return false;
        }

        return $this->accepts === null || ($this->accepts)($key, $request);
    }

    /**
     * The pattern a selectable key matches, as an ECMA-262 fragment.
     */
    public function keyPattern(): string
    {
        return $this->pattern ?? self::ANY_KEY_PATTERN;
    }
}
