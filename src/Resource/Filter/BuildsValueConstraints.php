<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * The mutable **value-constraint authoring** surface shared by the value-carrying
 * filter *builders* ({@see WhereBuilder}, {@see RangeBuilder}, {@see WhereInBuilder},
 * …). It accumulates the declared {@see ConstraintInterface} value constraints and
 * the OpenAPI description / example, mirroring the {@see \haddowg\JsonApi\Resource\Field\Id}
 * field builder's `uuid()` / `numeric()` / `pattern()` shortcuts and reusing the
 * same core constraint vocabulary. The mirror **consumption** surface (reading the
 * accumulated metadata back) lives on the value objects via {@see ExposesValueMetadata}.
 *
 * A self-contained readonly filter that is its own value object (no separate
 * builder) can instead reuse the copy-on-write {@see HasValueConstraints} trait.
 *
 * Constraints are **metadata only** — core never executes them (like every other
 * {@see ConstraintInterface}). A framework adapter translates them to its native
 * validator and checks a client-supplied `filter[<key>]` value **before** the
 * filter reaches the data layer, so a mistyped value (`filter[age]=banana` on an
 * integer column) is a clean `400` {@see \haddowg\JsonApi\Exception\FilterValueInvalid}
 * rather than the provider's silent non-match (or, on a strict driver, a PDO `500`).
 *
 * The builders are mutable, so {@see constrain()} and the shortcuts mutate and
 * return `$this`, exactly like the other fluent setters ({@see WhereBuilder::singular()}
 * / {@see WhereBuilder::default()}). {@see build()} then freezes the accumulated
 * `$constraints` / `$description` / `$example` into the readonly value object.
 *
 * @property list<ConstraintInterface> $constraints
 */
trait BuildsValueConstraints
{
    /**
     * The declared value constraints, in declaration order.
     *
     * @var list<ConstraintInterface>
     */
    protected array $constraints = [];

    protected ?string $description = null;

    protected bool $hasExample = false;

    protected mixed $example = null;

    /**
     * Sets a human-readable description surfaced by the OpenAPI generator.
     *
     * @return static
     */
    public function describedAs(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Sets an example value surfaced by the OpenAPI generator. A declared `null`
     * is honoured (distinct from "no example").
     *
     * @return static
     */
    public function example(mixed $example): static
    {
        $this->hasExample = true;
        $this->example = $example;

        return $this;
    }

    /**
     * Appends one or more value constraints.
     *
     * @return static
     */
    public function constrain(ConstraintInterface ...$constraints): static
    {
        $this->constraints = \array_values(\array_merge($this->constraints, $constraints));

        return $this;
    }

    /**
     * The value must be a base-10 number (integer or decimal, optional sign).
     * Documents as an OpenAPI `number` (the wire string is validated by the regex).
     *
     * @return static
     */
    public function numeric(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+(?:\.[0-9]+)?$', documentsAs: 'number'));
    }

    /**
     * The value must be a base-10 integer (optional sign, no decimal point).
     * Documents as an OpenAPI `integer` (the wire string is validated by the regex).
     *
     * @return static
     */
    public function integer(): static
    {
        return $this->constrain(new Pattern('^-?[0-9]+$', documentsAs: 'integer'));
    }

    /**
     * The value must be a UUID. An optional `$version` (1–8) narrows to a specific
     * RFC 4122 version; `null` allows any.
     *
     * @return static
     */
    public function uuid(?int $version = null): static
    {
        return $this->constrain(new UuidFormat($version));
    }

    /**
     * The value must be a boolean wire form accepted by `FILTER_VALIDATE_BOOLEAN`:
     * `1`/`true`/`on`/`yes` (truthy) or `0`/`false`/`off`/`no`/`''` (falsy),
     * case-insensitively and with optional surrounding whitespace — exactly the
     * vocabulary {@see WhereBuilder::asBoolean()} coerces, so the {@see Boolean}
     * filter's coercion, validation and OpenAPI value schema all accept the same set
     * (they must not drift apart). Documents as an OpenAPI `boolean` (the wire string
     * is validated by the regex).
     *
     * @return static
     */
    public function boolean(): static
    {
        return $this->constrain(new Pattern('^\s*(?i:true|false|1|0|on|off|yes|no)\s*$|^\s*$', documentsAs: 'boolean'));
    }

    /**
     * The value must match an ECMA-262 regular expression source (no delimiters).
     *
     * @return static
     */
    public function pattern(string $regex): static
    {
        return $this->constrain(new Pattern($regex));
    }
}
