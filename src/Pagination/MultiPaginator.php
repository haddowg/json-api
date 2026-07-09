<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Pagination;

use haddowg\JsonApi\Exception\PaginationKindUnknown;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A server-composed **menu** of pagination strategies that is itself a
 * {@see PaginatorInterface}, so it drops in wherever a single paginator does — a
 * resource's `pagination()`, a relation's `paginate()`, or the server default —
 * with no change to those signatures. The client selects one strategy per request;
 * the author declares the menu, so a client can never invent a strategy that was
 * not offered.
 *
 * ```php
 * MultiPaginator::make(
 *     PagePaginator::make()->withDefaultPerPage(20),
 *     CursorPaginator::make(),
 * )->default('cursor');
 * ```
 *
 * **Selection** ({@see resolve()}) is discriminator-first but not discriminator-only:
 * - an explicit `page[kind]=<kind>` selects that child (an unknown kind is a
 *   {@see PaginationKindUnknown} `400`);
 * - otherwise a **strategy-unique** `page[<key>]` — one that only a single child
 *   reads (`after`/`before` → cursor, `offset`/`limit` → offset) — selects its owner
 *   without a discriminator, honouring the cursor-pagination profile's bare params;
 * - otherwise (an absent `page`, or only **shared** keys such as `size`/`number`) it
 *   falls back to the author's {@see default()} (the first-declared child if unset).
 *
 * The same rule is encoded in the projected OpenAPI schema ({@see describePageSchema()}):
 * a `oneOf` of the children's page objects, each with an optional `kind` const and
 * `additionalProperties: false`, so a unique-key object matches exactly one branch
 * while a shared-key-only object matches several and is invalid until `page[kind]`
 * disambiguates it.
 */
final class MultiPaginator implements PaginatorInterface
{
    /**
     * The reserved discriminator key: `page[kind]` names the strategy directly.
     */
    public const string KIND_KEY = 'kind';

    /**
     * The menu, keyed by each child's {@see PaginatorInterface::kind()} and holding
     * declaration order (PHP preserves insertion order).
     *
     * @var non-empty-array<string, PaginatorInterface>
     */
    private array $children;

    /**
     * The kind selected when the request carries no discriminating `page[…]`.
     */
    private string $defaultKind;

    /**
     * Per child kind, the `page[<key>]` names unique to that child across the whole
     * menu — the keys that select it without a `page[kind]` discriminator.
     *
     * @var array<string, list<string>>
     */
    private array $uniqueKeys;

    /**
     * This wrapper's own {@see kind()} — irrelevant to selection (a menu is not
     * nested inside another menu in practice), overridable via {@see withKind()}.
     */
    private string $kind = 'multi';

    public function __construct(PaginatorInterface ...$children)
    {
        if (\count($children) === 0) {
            throw new \LogicException('A MultiPaginator requires at least one child paginator.');
        }

        $keyed = [];
        foreach ($children as $child) {
            $childKind = $child->kind();
            if (isset($keyed[$childKind])) {
                throw new \LogicException(
                    "Duplicate paginator kind '$childKind' in a MultiPaginator menu; give one a distinct kind() via withKind().",
                );
            }
            $keyed[$childKind] = $child;
        }

        $this->children = $keyed;
        $this->defaultKind = $children[0]->kind();
        $this->uniqueKeys = self::computeUniqueKeys($keyed);
    }

    /**
     * Composes a menu from the given strategies (variadic, self-naming by each
     * child's {@see PaginatorInterface::kind()}). Two children reporting the same
     * kind is a wiring bug ({@see \LogicException}).
     */
    public static function make(PaginatorInterface ...$children): self
    {
        return new self(...$children);
    }

    /**
     * Sets the strategy used when a request carries no discriminating `page[…]`
     * (the empty-`page` fallback). Defaults to the first-declared child. The kind
     * must name a child of this menu.
     */
    public function default(string $kind): self
    {
        if (!isset($this->children[$kind])) {
            throw new \LogicException(
                "Cannot default a MultiPaginator to unknown kind '$kind'; the menu offers: " . \implode(', ', $this->kinds()) . '.',
            );
        }

        $self = clone $this;
        $self->defaultKind = $kind;

        return $self;
    }

    /**
     * Renames this wrapper's own {@see kind()}. Rarely needed — selection is driven
     * by the children's kinds, not the wrapper's.
     */
    public function withKind(string $kind): self
    {
        $self = clone $this;
        $self->kind = $kind;

        return $self;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * The child strategies, in declaration order.
     *
     * @return non-empty-list<PaginatorInterface>
     */
    public function children(): array
    {
        return \array_values($this->children);
    }

    /**
     * The kinds this menu offers, in declaration order.
     *
     * @return non-empty-list<string>
     */
    public function kinds(): array
    {
        return \array_keys($this->children);
    }

    public function describePageSchema(): Schema
    {
        $branches = [];
        foreach ($this->children as $childKind => $child) {
            $branches[] = $child->describePageSchema()
                ->withProperty(
                    self::KIND_KEY,
                    Schema::ofType('string')->withConst($childKind)->withDescription('Selects the pagination strategy.'),
                )
                ->withAdditionalProperties(false);
        }

        return Schema::create()
            ->withOneOf($branches)
            ->withDiscriminator(self::KIND_KEY);
    }

    public function resolve(JsonApiRequestInterface $request): PaginatorInterface
    {
        $page = $request->getPagination();

        // 1. An explicit discriminator wins outright.
        $requestedKind = $page[self::KIND_KEY] ?? null;
        if (\is_string($requestedKind) && $requestedKind !== '') {
            $child = $this->children[$requestedKind] ?? null;
            if ($child === null) {
                throw new PaginationKindUnknown($requestedKind, $this->kinds());
            }

            return $child;
        }

        // 2. A strategy-unique key selects its owner without a discriminator
        //    (declaration order breaks a tie between two children's unique keys).
        foreach ($this->children as $childKind => $child) {
            foreach ($this->uniqueKeys[$childKind] as $key) {
                if (\array_key_exists($key, $page)) {
                    return $child;
                }
            }
        }

        // 3. Absent page, or only shared keys — fall back to the declared default.
        return $this->children[$this->defaultKind];
    }

    public function window(JsonApiRequestInterface $request): WindowInterface
    {
        return $this->resolve($request)->window($request);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageInterface<mixed>
     */
    public function paginate(JsonApiRequestInterface $request, iterable $items, int $totalItems): PageInterface
    {
        return $this->resolve($request)->paginate($request, $items, $totalItems);
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return PageInterface<mixed>
     */
    public function paginateWithoutCount(JsonApiRequestInterface $request, iterable $items, bool $hasMore): PageInterface
    {
        return $this->resolve($request)->paginateWithoutCount($request, $items, $hasMore);
    }

    public function wantsCount(): bool
    {
        return $this->children[$this->defaultKind]->wantsCount();
    }

    /**
     * Builds the per-child unique-key map: a `page[<key>]` read by exactly one child
     * across the menu is that child's unique key; a key read by two or more children
     * (`size`, `number`) is shared and needs `page[kind]` to disambiguate.
     *
     * @param non-empty-array<string, PaginatorInterface> $children
     *
     * @return array<string, list<string>>
     */
    private static function computeUniqueKeys(array $children): array
    {
        /** @var array<string, int> $frequency */
        $frequency = [];
        /** @var array<string, list<string>> $keysByChild */
        $keysByChild = [];
        foreach ($children as $childKind => $child) {
            $keysByChild[$childKind] = self::pageKeysOf($child);
            foreach ($keysByChild[$childKind] as $key) {
                $frequency[$key] = ($frequency[$key] ?? 0) + 1;
            }
        }

        $unique = [];
        foreach ($keysByChild as $childKind => $keys) {
            $unique[$childKind] = \array_values(\array_filter(
                $keys,
                static fn(string $key): bool => ($frequency[$key] ?? 0) === 1,
            ));
        }

        return $unique;
    }

    /**
     * The `page[<key>]` parameter names a child reads — the property names of its
     * page schema, minus the reserved discriminator.
     *
     * @return list<string>
     */
    private static function pageKeysOf(PaginatorInterface $child): array
    {
        $properties = $child->describePageSchema()->get('properties');
        if (!\is_array($properties)) {
            return [];
        }

        $keys = [];
        foreach (\array_keys($properties) as $key) {
            if (\is_string($key) && $key !== self::KIND_KEY) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
