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
 * It also carries the **description / example** authoring metadata the OpenAPI
 * generator reads when projecting this filter's `filter[<key>]` query parameter
 * (forward use — the filter→parameter projection lands in a later slice). Both are
 * immutable withers like {@see constrain()}, backed by the host's
 * {@see withDescriptionAndExample()} seam.
 *
 * @phpstan-require-implements DescribedFilter
 *
 * @property-read list<ConstraintInterface> $constraints
 * @property-read ?string                   $description
 * @property-read bool                      $hasExample
 * @property-read mixed                     $example
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
     * Sets a human-readable description surfaced by the OpenAPI generator.
     * Immutable: returns a new instance.
     */
    public function describedAs(string $description): static
    {
        return $this->withDescriptionAndExample($description, $this->hasExample, $this->example);
    }

    /**
     * Sets an example value surfaced by the OpenAPI generator. A declared `null`
     * is honoured (distinct from "no example"). Immutable: returns a new instance.
     */
    public function example(mixed $example): static
    {
        return $this->withDescriptionAndExample($this->description, true, $example);
    }

    /**
     * The declared description surfaced by the OpenAPI generator, or `null`.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Whether an example value was declared (distinct from a declared `null`).
     */
    public function hasExample(): bool
    {
        return $this->hasExample;
    }

    /**
     * The declared example value; only meaningful when {@see hasExample()} is true.
     */
    public function getExample(): mixed
    {
        return $this->example;
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
     * Documents as an OpenAPI `number` (the wire string is validated by the regex).
     */
    public function numeric(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+(?:\.[0-9]+)?$', documentsAs: 'number'));
    }

    /**
     * The value must be a base-10 integer (optional sign, no decimal point).
     * Documents as an OpenAPI `integer` (the wire string is validated by the regex).
     */
    public function integer(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+$', documentsAs: 'integer'));
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
     * The value must be a boolean wire form accepted by `FILTER_VALIDATE_BOOLEAN`:
     * `1`/`true`/`on`/`yes` (truthy) or `0`/`false`/`off`/`no`/`''` (falsy),
     * case-insensitively and with optional surrounding whitespace — exactly the
     * vocabulary {@see Where::asBoolean()} coerces, so the {@see Boolean} filter's
     * coercion, validation and OpenAPI value schema all accept the same set (they
     * must not drift apart). Documents as an OpenAPI `boolean` (the wire string is
     * validated by the regex).
     */
    public function boolean(): static
    {
        return $this->constrain(new Pattern('^\s*(?i:true|false|1|0|on|off|yes|no)\s*$|^\s*$', documentsAs: 'boolean'));
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

    /**
     * Rebuilds the host filter with the given description / example metadata. The
     * host alone knows its constructor shape, so each value-carrying filter
     * implements this.
     */
    abstract protected function withDescriptionAndExample(?string $description, bool $hasExample, mixed $example): static;
}
