<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * Declares **value constraints** on a value-carrying filter and the fluent
 * builders that append them, mirroring the {@see \haddowg\JsonApi\Resource\Field\Id}
 * field's `uuid()` / `numeric()` / `pattern()` shortcuts and reusing the same
 * core {@see ConstraintInterface} vocabulary.
 *
 * Constraints are **metadata only** — core never executes them (like every other
 * {@see ConstraintInterface}). A framework adapter translates them to its native
 * validator and checks a client-supplied `filter[<key>]` value **before** the
 * filter reaches the data layer, so a mistyped value (`filter[age]=banana` on an
 * integer column) is a clean `400` {@see \haddowg\JsonApi\Exception\FilterValueInvalid}
 * rather than the provider's silent non-match (or, on a strict driver, a PDO `500`).
 *
 * The filter VOs are immutable, so {@see constrain()} and the shortcuts are
 * withers: each returns a new instance with the constraint appended, exactly like
 * the existing `singular()` / `default()` / `deserializeUsing()` refinements. The
 * host filter supplies {@see withConstraints()} (it alone knows its constructor).
 *
 * @phpstan-require-implements FilterInterface
 *
 * @property-read list<ConstraintInterface> $constraints
 */
trait HasValueConstraints
{
    /**
     * The declared value constraints, in declaration order.
     *
     * @return list<ConstraintInterface>
     */
    public function constraints(): array
    {
        return $this->constraints;
    }

    /**
     * Appends one or more value constraints. Immutable: returns a new instance.
     */
    public function constrain(ConstraintInterface ...$constraints): static
    {
        return $this->withConstraints(\array_values(\array_merge($this->constraints, $constraints)));
    }

    /**
     * The value must be a base-10 number (integer or decimal, optional sign).
     */
    public function numeric(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+(?:\.[0-9]+)?$'));
    }

    /**
     * The value must be a base-10 integer (optional sign, no decimal point).
     */
    public function integer(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+$'));
    }

    /**
     * The value must be a UUID. An optional `$version` (1–8) narrows to a specific
     * RFC 4122 version; `null` allows any.
     */
    public function uuid(?int $version = null): static
    {
        return $this->constrain(new UuidFormat($version));
    }

    /**
     * The value must be a boolean wire form: `true`, `false`, `1` or `0`.
     */
    public function boolean(): static
    {
        return $this->constrain(new Pattern('^(?:true|false|1|0)$'));
    }

    /**
     * The value must match an ECMA-262 regular expression source (no delimiters).
     */
    public function pattern(string $regex): static
    {
        return $this->constrain(new Pattern($regex));
    }

    /**
     * Rebuilds the host filter with the given constraint list. The host alone
     * knows its constructor shape, so each value-carrying filter implements this.
     *
     * @param list<ConstraintInterface> $constraints
     */
    abstract protected function withConstraints(array $constraints): static;
}
