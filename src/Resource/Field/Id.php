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
 *
 * Two orthogonal axes govern where a create's id comes from:
 *
 * - **Client-id acceptance** (default: forbidden). {@see allowClientId()} makes a
 *   client-supplied `data.id` optional, {@see requireClientId()} makes it
 *   mandatory; read via {@see allowsClientId()} / {@see requiresClientId()}.
 * - **Server-side fallback** when the client supplies none (default: store-provided
 *   — core sets nothing and the store/DB assigns the id). {@see generated()} mints
 *   one from the declared format ({@see uuid()} / {@see ulid()}); {@see generateUsing()}
 *   takes a closure returning the storage key. Read via {@see generateIdValue()}.
 */
final class Id extends AbstractAttribute
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
        $this->format ??= IdFormat::Uuid;

        return $this->addConstraint(new UuidFormat($version, $this->currentContext()));
    }

    /**
     * @return static
     */
    public function ulid(): static
    {
        $this->routePattern ??= self::ULID_FORMAT_PATTERN;
        $this->format ??= IdFormat::Ulid;

        return $this->addConstraint(new UlidFormat($this->currentContext()));
    }

    /**
     * @return static
     */
    public function numeric(): static
    {
        $this->routePattern ??= self::NUMERIC_FORMAT_PATTERN;
        $this->format ??= IdFormat::Numeric;

        return $this->addConstraint(new Pattern('^' . self::NUMERIC_FORMAT_PATTERN . '$', $this->currentContext()));
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
     * Whether a client-supplied id is accepted (optional or required).
     */
    public function allowsClientId(): bool
    {
        return $this->clientIdPolicy !== ClientIdPolicy::Forbidden;
    }

    /**
     * Whether a client-supplied id is mandatory.
     */
    public function requiresClientId(): bool
    {
        return $this->clientIdPolicy === ClientIdPolicy::Required;
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
     * The server-side fallback value to set when the client supplies no id, or
     * `null` when the id is store-provided (core sets nothing; the store/DB
     * assigns it). For a format-generated id this mints a fresh value on each
     * call; for a closure it invokes the closure.
     */
    public function generateIdValue(): ?string
    {
        return match ($this->source) {
            IdSource::Format => $this->format === IdFormat::Ulid ? Ulid::generate() : self::generateUuid(),
            IdSource::Closure => ($this->generator ?? static fn(): string => '')(),
            null => null,
        };
    }

    /**
     * Generates an RFC 4122 v4 UUID. The id-field implementation of the `uuid()`
     * format generator, used when the id is declared `uuid()->generated()`.
     */
    public static function generateUuid(): string
    {
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($bytes), 4));
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
