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
final readonly class FilterFamily implements FilterFamilyInterface, AcceptsList, DescribedFilter, ExemplifiedFilter, \haddowg\JsonApi\Resource\Deprecatable
{
    /**
     * @param \Closure(string, JsonApiRequestInterface): ?FilterInterface $resolver
     * @param array<string, mixed>|null                                  $example  one full key and its value
     */
    private function __construct(
        private string $prefix,
        private \Closure $resolver,
        private string $suffixPattern,
        private ?ListValue $list = null,
        private bool $deprecated = false,
        private ?string $description = null,
        private ?array $example = null,
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
        if ($this->example !== null) {
            self::assertExampleSuffix($this->prefix, $pattern, \substr((string) \array_key_first($this->example), \strlen($this->prefix) + 1));
        }

        return new self($this->prefix, $this->resolver, $pattern, $this->list, $this->deprecated, $this->description, $this->example);
    }

    /**
     * A copy whose filters take a list value ({@see AcceptsList}): the array form always,
     * and a comma-delimited string unless `$delimited` is `false`. Every filter the
     * resolver returns must declare the same list.
     */
    public function acceptsList(bool $delimited = true): self
    {
        return new self($this->prefix, $this->resolver, $this->suffixPattern, $delimited ? ListValue::delimited() : ListValue::arrayOnly(), $this->deprecated, $this->description, $this->example);
    }

    /**
     * A copy marked deprecated in the OpenAPI document: the family's entry in
     * `x-filter-families` carries `deprecated: true`. Documentation only.
     */
    public function deprecated(): self
    {
        return new self($this->prefix, $this->resolver, $this->suffixPattern, $this->list, true, $this->description, $this->example);
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    /**
     * A copy carrying a description of what the family's keys filter by. The OpenAPI
     * document writes it into the family's `x-filter-families` entry and as one line of
     * the shared `filter` parameter's description.
     */
    public function describedAs(string $description): self
    {
        return new self($this->prefix, $this->resolver, $this->suffixPattern, $this->list, $this->deprecated, $description, $this->example);
    }

    /**
     * A copy carrying an example of one key the family answers and the value sent with
     * it: `example('cap', 10)` on the family `value` shows `filter[value.cap]=10`. The
     * OpenAPI document writes `{"value.cap": 10}` into the family's `x-filter-families`
     * entry and into the example of the shared `filter` parameter.
     *
     * @throws \InvalidArgumentException when the suffix is one the family does not accept
     */
    public function example(string $suffix, mixed $value): self
    {
        self::assertExampleSuffix($this->prefix, $this->suffixPattern, $suffix);

        return new self($this->prefix, $this->resolver, $this->suffixPattern, $this->list, $this->deprecated, $this->description, [$this->prefix . '.' . $suffix => $value]);
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function hasExample(): bool
    {
        return $this->example !== null;
    }

    /**
     * The declared example as one full key and its value, or `null` when none was
     * declared.
     *
     * @return array<string, mixed>|null
     */
    public function getExample(): ?array
    {
        return $this->example;
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

    private static function assertExampleSuffix(string $prefix, string $pattern, string $suffix): void
    {
        if ($suffix === '' || \preg_match(KeyFamilyMatcher::suffixRegex($pattern), $suffix) !== 1) {
            throw new \InvalidArgumentException(\sprintf('The example suffix "%s" of filter family "%s" does not match its suffix pattern "%s".', $suffix, $prefix, $pattern));
        }
    }
}
