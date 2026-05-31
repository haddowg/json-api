<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * The resource identifier (`id`) member. Unlike attribute fields it is rendered
 * into the resource's top-level `id` (not `attributes`) and hydrated via the
 * hydrator's id hook, so a schema treats it specially. Defaults to reading the
 * `id` column / `getId()` accessor on the domain object.
 *
 * The `uuid()` / `numeric()` / `pattern()` shortcuts append the matching
 * client-generated-id format constraint.
 */
final class Id extends AbstractField
{
    /**
     * @return static
     */
    public static function make(string $name = 'id'): static
    {
        return new static($name);
    }

    /**
     * @return static
     */
    public function uuid(?int $version = null): static
    {
        return $this->addConstraint(new UuidFormat($version, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function numeric(): static
    {
        return $this->addConstraint(new Pattern('^[0-9]+$', $this->currentContext()));
    }

    /**
     * @return static
     */
    public function pattern(string $regex): static
    {
        return $this->addConstraint(new Pattern($regex, $this->currentContext()));
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_scalar($raw) ? (string) $raw : $raw);
    }
}
