<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;

/**
 * The mutable **field builder** for the resource identifier (`id`) member. Adds
 * the id-format / route-requirement / encoder / client-id / generation helpers on
 * top of the common {@see AbstractFieldBuilder} surface; {@see build()} freezes it
 * into a readonly {@see Id} value object.
 *
 * The `uuid()` / `ulid()` / `numeric()` / `pattern()` shortcuts append the matching
 * client-generated-id format constraint **and** set the route `{id}` requirement, so
 * one call governs both the create-id validation and the URL pattern.
 * {@see matchAs()} sets the route requirement on its own.
 *
 * Two orthogonal axes govern where a create's id comes from:
 *
 * - **Client-id acceptance** (default: forbidden). {@see allowClientId()} makes a
 *   client-supplied `data.id` optional, {@see requireClientId()} makes it mandatory.
 * - **Server-side fallback** when the client supplies none (default: store-provided
 *   — core sets nothing and the store/DB assigns the id). {@see generated()} mints
 *   one from the declared format ({@see uuid()} / {@see ulid()}); {@see generateUsing()}
 *   takes a closure returning the storage key.
 */
final class IdBuilder extends AbstractFieldBuilder
{
    private ?IdEncoderInterface $encoder = null;

    private ?string $routePattern = null;

    /**
     * The declared id format, set by the {@see uuid()} / {@see ulid()} /
     * {@see numeric()} / {@see pattern()} shortcuts. Drives {@see generated()},
     * which can only mint a value from a self-generating format.
     */
    private ?IdFormat $format = null;

    private ClientIdPolicy $clientIdPolicy = ClientIdPolicy::Forbidden;

    /**
     * The server-side fallback when the client supplies no id. `null` means
     * store-provided (core sets nothing); otherwise the value is minted on demand.
     */
    private ?IdSource $source = null;

    /**
     * A closure returning the generated storage key, set by {@see generateUsing()}.
     *
     * @var (\Closure(): string)|null
     */
    private ?\Closure $generator = null;

    public function build(): Id
    {
        return new Id(
            $this->fieldState(),
            $this->encoder,
            $this->routePattern,
            $this->format,
            $this->clientIdPolicy,
            $this->source,
            $this->generator,
        );
    }

    /**
     * @return static
     */
    public function uuid(?int $version = null): static
    {
        $this->routePattern ??= Id::UUID_FORMAT_PATTERN;
        $this->format ??= IdFormat::Uuid;

        return $this->addConstraint(new UuidFormat($version, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function ulid(): static
    {
        $this->routePattern ??= Id::ULID_FORMAT_PATTERN;
        $this->format ??= IdFormat::Ulid;

        return $this->addConstraint(new UlidFormat($this->currentContext()));
    }

    /**
     * @return static
     */
    public function numeric(): static
    {
        $this->routePattern ??= Id::NUMERIC_FORMAT_PATTERN;
        $this->format ??= IdFormat::Numeric;

        return $this->addConstraint(new Pattern('^' . Id::NUMERIC_FORMAT_PATTERN . '$', $this->currentContext()));
    }

    /**
     * @return static
     */
    public function pattern(string $regex): static
    {
        $this->routePattern ??= self::stripAnchors($regex);
        $this->format ??= IdFormat::Pattern;

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
     * Accepts a client-supplied `data.id` on create as **optional** — used when
     * supplied (validated against the declared format), generated otherwise. The
     * default is to reject any client id with `ClientGeneratedIdNotSupported`.
     *
     * @return static
     */
    public function allowClientId(): static
    {
        $this->clientIdPolicy = ClientIdPolicy::Optional;

        return $this;
    }

    /**
     * Requires a client-supplied `data.id` on create: a create without one yields
     * a `403` `ClientGeneratedIdRequired`.
     *
     * @return static
     */
    public function requireClientId(): static
    {
        $this->clientIdPolicy = ClientIdPolicy::Required;

        return $this;
    }

    /**
     * Core mints the id from the declared format when the client supplies none —
     * `uuid()` mints a v4 UUID, `ulid()` a Crockford-base32 ULID. The default
     * (without this call) is store-provided: core sets nothing and the store/DB
     * assigns the id.
     *
     * @throws \LogicException when no self-generating format is declared (the
     *                         format must be `uuid()` or `ulid()`)
     *
     * @return static
     */
    public function generated(): static
    {
        if ($this->format !== IdFormat::Uuid && $this->format !== IdFormat::Ulid) {
            throw new \LogicException(
                'Id::generated() requires a self-generating format: declare uuid() or ulid() '
                . '(numeric(), pattern() and a format-less id cannot be generated — supply generateUsing() '
                . 'or leave the id store-provided).',
            );
        }

        $this->source = IdSource::Format;
        $this->generator = null;

        return $this;
    }

    /**
     * Core mints the id with `$fn` when the client supplies none. The closure
     * returns the **storage key** directly (it is not decoded — only a client wire
     * id is). Supersedes any {@see generated()} format generation.
     *
     * @param \Closure(): string $fn
     * @return static
     */
    public function generateUsing(\Closure $fn): static
    {
        $this->source = IdSource::Closure;
        $this->generator = $fn;

        return $this;
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
