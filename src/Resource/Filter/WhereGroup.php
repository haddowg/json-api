<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;

/**
 * Base for the **server-composed** boolean filter groups {@see WhereAll} (AND)
 * and {@see WhereAny} (OR): a `key()` plus a `list<FilterInterface> $children`
 * the resource author composes on the server — the built, readonly value object a
 * handler consumes (`instanceof WhereAll` / `instanceof WhereAny`, then
 * `->children`). The group combines its children under one `filter[<key>]` key and
 * **passes its own request value uniformly to every child** — so a group of
 * value-carrying children fans one value across columns
 * (`WhereAny::make('q', Contains::make('name'), Contains::make('email'))` →
 * `filter[q]=foo` matches `name LIKE foo OR email LIKE foo`), while a group of
 * {@see WhereBuilder::fixed() fixed} children is a canned toggle whose request value
 * is ignored. The two even mix (a fixed child ignores the passed value).
 *
 * A child's own `key()` is **ignored as a request parameter** — only the group's
 * key is a `filter[...]` — but still drives the child's target column/operator, so
 * `Contains::make('name')` filters column `name` whatever the group key is. Groups
 * may **nest arbitrarily** (`WhereAll::make('x', A, WhereAny::make('y', B, C))` →
 * `A AND (B OR C)`): a group child is itself a {@see FilterInterface}, so a handler
 * re-enters the same dispatch for it. This stays owner-vetted — the client cannot
 * assemble arbitrary boolean algebra — so it does not reintroduce a client-driven
 * `filter[and]`/`[or]` model.
 *
 * The group carries the value-metadata consumption surface ({@see ExposesValueMetadata})
 * exactly like {@see Where}, so a fanning group can carry its shared value's declared
 * constraints (authored via {@see WhereGroupBuilder}); an all-fixed group declares
 * none. Not `final`: the AND/OR distinction is the concrete subclass, dispatched by
 * a handler's `instanceof WhereAll` / `instanceof WhereAny` arm.
 */
abstract readonly class WhereGroup implements \haddowg\JsonApi\Resource\Filter\DescribedFilter, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    use \haddowg\JsonApi\Resource\Filter\ExposesValueMetadata;

    /**
     * @param list<FilterInterface>     $children    the filters this group combines
     * @param list<ConstraintInterface> $constraints declared constraints for the group's shared value
     */
    public function __construct(
        public string $key,
        public array $children = [],
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    /**
     * The child filters this group combines, in declaration order.
     *
     * @return list<FilterInterface>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * A group is presence-triggered — its request value is ignored — only when
     * **every** child is itself presence-triggered (all children fixed, possibly
     * through nested all-fixed groups). If any child consumes the value (a fanning
     * search child), the group's value *is* a client input, so it is not
     * presence-triggered. An empty group is not presence-triggered.
     */
    public function isPresenceTriggered(): bool
    {
        if ($this->children === []) {
            return false;
        }

        foreach ($this->children as $child) {
            if (!$child instanceof PresenceTriggeredFilter || !$child->isPresenceTriggered()) {
                return false;
            }
        }

        return true;
    }
}
