<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Sort;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A {@see SortFamilyInterface} backed by a closure:
 *
 * ```php
 * SortFamily::make('value', fn(string $suffix, JsonApiRequestInterface $request): ?SortInterface
 *     => new PlaceholderValueSort('value.' . $suffix, $suffix));
 * ```
 */
final readonly class SortFamily implements SortFamilyInterface
{
    /**
     * @param \Closure(string, JsonApiRequestInterface): ?SortInterface $resolver
     */
    private function __construct(
        private string $prefix,
        private \Closure $resolver,
        private string $suffixPattern,
    ) {}

    /**
     * @param string                                                   $prefix   the key prefix, matched as `<prefix>.<suffix>`
     * @param callable(string, JsonApiRequestInterface): ?SortInterface $resolver the concrete sort for a suffix, `null` when it names nothing
     */
    public static function make(string $prefix, callable $resolver): self
    {
        if ($prefix === '' || \str_contains($prefix, ',') || \str_starts_with($prefix, '-')) {
            throw new \InvalidArgumentException(\sprintf('The sort family prefix "%s" must be non-empty, contain no comma and not start with "-".', $prefix));
        }

        return new self($prefix, \Closure::fromCallable($resolver), self::DEFAULT_SUFFIX_PATTERN);
    }

    /**
     * Narrows the suffixes this family accepts to those matching `$pattern` whole, e.g.
     * `[a-z_]+`. A key whose suffix fails it is unrecognized without calling the resolver.
     */
    public function withSuffixPattern(string $pattern): self
    {
        if ($pattern === '' || @\preg_match(SortMatcher::suffixRegex($pattern), '') === false) {
            throw new \InvalidArgumentException(\sprintf('The suffix pattern "%s" of sort family "%s" is not a valid regular expression.', $pattern, $this->prefix));
        }

        return new self($this->prefix, $this->resolver, $pattern);
    }

    public function key(): string
    {
        return $this->prefix;
    }

    public function suffixPattern(): string
    {
        return $this->suffixPattern;
    }

    public function resolve(string $suffix, JsonApiRequestInterface $request): ?SortInterface
    {
        return ($this->resolver)($suffix, $request);
    }
}
