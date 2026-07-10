<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The resource identifier (`id`) member — the built, readonly value object the
 * engine walks. Unlike attribute fields it is rendered into the resource's
 * top-level `id` (not `attributes`) and hydrated via the hydrator's id hook, so a
 * schema treats it specially. Defaults to reading the `id` column / `getId()`
 * accessor on the domain object. Authors declare one with {@see make()}, which
 * returns a mutable {@see IdBuilder}; the resource **builds** it into this value
 * object before use.
 *
 * An {@see IdEncoderInterface} attached with {@see IdBuilder::encodeUsing()} makes
 * the id the wire form of a distinct storage key: {@see serializeValue()} encodes
 * the stored key on the way out, and the hydrator decodes a client-generated id
 * back to the storage key on the way in.
 *
 * Two orthogonal axes govern where a create's id comes from — client-id acceptance
 * (read via {@see allowsClientId()} / {@see requiresClientId()}) and the server-side
 * fallback when the client supplies none (read via {@see generateIdValue()}).
 */
final readonly class Id extends AbstractFieldValue
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

    /**
     * @param (\Closure(): string)|null $generator
     */
    public function __construct(
        FieldState $state,
        private ?IdEncoderInterface $encoder = null,
        private ?string $routePattern = null,
        private ?IdFormat $format = null,
        private ClientIdPolicy $clientIdPolicy = ClientIdPolicy::Forbidden,
        private ?IdSource $source = null,
        private ?\Closure $generator = null,
    ) {
        parent::__construct($state);
    }

    /**
     * @return IdBuilder
     */
    public static function make(string $name = 'id'): IdBuilder
    {
        return new IdBuilder($name);
    }

    /**
     * The route `{id}` requirement, or `null` when the id is unconstrained.
     */
    public function routePattern(): ?string
    {
        return $this->routePattern;
    }

    /**
     * The attached id encoder, or `null` when wire == storage.
     */
    public function encoder(): ?IdEncoderInterface
    {
        return $this->encoder;
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
}
