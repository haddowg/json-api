<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * The resource identifier (`id`) member. Unlike attribute fields it is rendered
 * into the resource's top-level `id` (not `attributes`) and hydrated via the
 * hydrator's id hook, so a schema treats it specially. Defaults to reading the
 * `id` column / `getId()` accessor on the domain object.
 *
 * The `uuid()` / `ulid()` / `numeric()` / `pattern()` shortcuts append the
 * matching client-generated-id format constraint **and** set the route `{id}`
 * requirement, so one call governs both the create-id validation and the URL
 * pattern. {@see matchAs()} sets the route requirement on its own.
 *
 * An {@see IdEncoderInterface} attached with {@see encodeUsing()} makes the id
 * the wire form of a distinct storage key: {@see serializeValue()} encodes the
 * stored key on the way out, and the hydrator decodes a client-generated id back
 * to the storage key on the way in.
 */
final class Id extends AbstractField
{
    /**
     * The inner regex (no surrounding `^`/`$`) for a UUID route requirement.
     */
    public const string UUID_FORMAT_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * The inner regex (no surrounding `^`/`$`) for a ULID — 26 Crockford base32
     * characters (case-insensitive), first char `0-7` to fit 128 bits.
     */
    public const string ULID_FORMAT_PATTERN = '[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}';

    /**
     * The inner regex (no surrounding `^`/`$`) for a numeric route requirement.
     */
    public const string NUMERIC_FORMAT_PATTERN = '[0-9]+';

    private ?IdEncoderInterface $encoder = null;

    private ?string $routePattern = null;

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
        $this->routePattern ??= self::UUID_FORMAT_PATTERN;

        return $this->addConstraint(new UuidFormat($version, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function ulid(): static
    {
        $this->routePattern ??= self::ULID_FORMAT_PATTERN;

        return $this->addConstraint(new UlidFormat($this->currentContext()));
    }

    /**
     * @return static
     */
    public function numeric(): static
    {
        $this->routePattern ??= self::NUMERIC_FORMAT_PATTERN;

        return $this->addConstraint(new Pattern('^' . self::NUMERIC_FORMAT_PATTERN . '$', $this->currentContext()));
    }

    /**
     * @return static
     */
    public function pattern(string $regex): static
    {
        $this->routePattern ??= self::stripAnchors($regex);

        return $this->addConstraint(new Pattern($regex, $this->currentContext()));
    }

    /**
     * Sets the route `{id}` requirement to `$pattern` — the **inner** regex for a
     * Symfony route requirement (Symfony anchors it; do not wrap it in `^…$`).
     *
     * @return static
     */
    public function matchAs(string $pattern): static
    {
        $this->routePattern = $pattern;

        return $this;
    }

    /**
     * The route `{id}` requirement, or `null` when the id is unconstrained.
     */
    public function routePattern(): ?string
    {
        return $this->routePattern;
    }

    /**
     * Encodes the id as the wire form of a distinct storage key.
     *
     * @return static
     */
    public function encodeUsing(IdEncoderInterface $encoder): static
    {
        $this->encoder = $encoder;

        return $this;
    }

    /**
     * The attached id encoder, or `null` when wire == storage.
     */
    public function encoder(): ?IdEncoderInterface
    {
        return $this->encoder;
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if ($this->encoder !== null && $raw !== null) {
            return (string) $this->encoder->encode($raw);
        }

        return $raw === null ? null : (\is_scalar($raw) ? (string) $raw : $raw);
    }

    /**
     * Strips a single leading `^` and trailing `$` from an ECMA-262 constraint
     * regex to yield the inner route requirement.
     */
    private static function stripAnchors(string $regex): string
    {
        if (\str_starts_with($regex, '^')) {
            $regex = \substr($regex, 1);
        }

        if (\str_ends_with($regex, '$')) {
            $regex = \substr($regex, 0, -1);
        }

        return $regex;
    }
}
