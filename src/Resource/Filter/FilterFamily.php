<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Internal\KeyFamilyMatcher;

/**
 * A {@see FilterFamilyInterface} backed by a closure:
 *
 * ```php
 * FilterFamily::make('value', fn(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
 *     => WhereIn::make('value.' . $suffix, $suffix)->acceptsList()->build())
 *     ->acceptsList();
 * ```
 */
final readonly class FilterFamily implements FilterFamilyInterface, AcceptsList, \haddowg\JsonApi\Resource\Deprecatable
{
    /**
     * @param \Closure(string, JsonApiRequestInterface): ?FilterInterface $resolver
     */
    private function __construct(
        private string $prefix,
        private \Closure $resolver,
        private string $suffixPattern,
        private ?ListValue $list = null,
        private bool $deprecated = false,
    ) {}

    /**
     * @param string                                                     $prefix   the key prefix, matched as `<prefix>.<suffix>`
     * @param callable(string, JsonApiRequestInterface): ?FilterInterface $resolver the concrete filter for a suffix, `null` when it names nothing
     */
    public static function make(string $prefix, callable $resolver): self
    {
        if ($prefix === '' || \str_contains($prefix, '[') || \str_contains($prefix, ']')) {
            throw new \InvalidArgumentException(\sprintf('The filter family prefix "%s" must be non-empty and contain no bracket.', $prefix));
        }

        return new self($prefix, \Closure::fromCallable($resolver), self::DEFAULT_SUFFIX_PATTERN);
    }

    /**
     * Narrows the suffixes this family accepts to those matching `$pattern` whole, e.g.
     * `[a-z_]+`. A key whose suffix fails it is unrecognized without calling the resolver.
     */
    public function withSuffixPattern(string $pattern): self
    {
        KeyFamilyMatcher::assertValidSuffixPattern($pattern, 'filter', $this->prefix);

        return new self($this->prefix, $this->resolver, $pattern, $this->list, $this->deprecated);
    }

    /**
     * A copy whose filters take a list value ({@see AcceptsList}): the array form always,
     * and a comma-delimited string unless `$delimited` is `false`. Every filter the
     * resolver returns must declare the same list.
     */
    public function acceptsList(bool $delimited = true): self
    {
        return new self($this->prefix, $this->resolver, $this->suffixPattern, $delimited ? ListValue::delimited() : ListValue::arrayOnly(), $this->deprecated);
    }

    /**
     * A copy marked deprecated in the OpenAPI document: the family's entry in
     * `x-filter-families` carries `deprecated: true`. Documentation only.
     */
    public function deprecated(): self
    {
        return new self($this->prefix, $this->resolver, $this->suffixPattern, $this->list, true);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function key(): string
    {
        return $this->prefix;
    }

    public function constraints(): array
    {
        return [];
    }

    public function listValue(): ?ListValue
    {
        return $this->list;
    }

    public function suffixPattern(): string
    {
        return $this->suffixPattern;
    }

    public function resolve(string $suffix, JsonApiRequestInterface $request): ?FilterInterface
    {
        return ($this->resolver)($suffix, $request);
    }
}
