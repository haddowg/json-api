<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * Matches rows that lack a related record on the named relationship (the
 * negation of {@see WhereHas}).
 */
final readonly class WhereDoesntHave implements \haddowg\JsonApi\Resource\Filter\FilterInterface, \haddowg\JsonApi\Resource\Filter\PresenceTriggeredFilter
{
    public function __construct(
        public string $key,
        public string $relationship,
    ) {}

    public static function make(string $key, ?string $relationship = null): self
    {
        return new self($key, $relationship ?? $key);
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Always presence-triggered: the relationship's existence decides the match, so
     * whatever value the request carries with the key is ignored.
     */
    public function isPresenceTriggered(): bool
    {
        return true;
    }

    /**
     * A presence-only filter ignores its request value, so there is nothing to
     * validate.
     *
     * @return list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface>
     */
    public function constraints(): array
    {
        return [];
    }
}
