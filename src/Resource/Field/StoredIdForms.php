<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The two forms a stored reference to a target can take. A polymorphic relation's id
 * column or link row holds the target's storage key, as a write stores it, or its wire
 * id, as a text column holding ids of several kinds often does. The two are the same
 * when the target's type declares no id encoder.
 */
final class StoredIdForms
{
    /**
     * The wire id a stored reference in either form names. A value the encoder decodes
     * and encodes back to the same id, ignoring case, is a wire id already, and anything
     * else is a storage key, encoded. With no encoder the stored id is the wire id, except
     * that a uuid-shaped one, in the canonical 8-4-4-4-12 hex form, is lower-cased, as a uuid
     * renders and as RFC 9562 writes it, so a linkage read from a column holding it in
     * capitals still names the target the include renders. Anything else passes through.
     */
    public static function wireId(?IdEncoderInterface $encoder, string|int $stored): string
    {
        if ($encoder === null) {
            return self::comparable($stored);
        }

        $decoded = $encoder->decode((string) $stored);
        if ($decoded !== null) {
            $wire = $encoder->encode($decoded);
            if (\strcasecmp($wire, (string) $stored) === 0) {
                return $wire;
            }
        }

        return $encoder->encode($stored);
    }

    /**
     * `$id` as it compares with another id: a uuid-like string, which a text column may
     * hold in either case, in lower case, and anything else as its string unchanged.
     */
    public static function comparable(string|int|\Stringable $id): string
    {
        $id = (string) $id;

        return self::isUuidLike($id) ? \strtolower($id) : $id;
    }

    /**
     * Whether `$id` is a uuid in its canonical 8-4-4-4-12 hex form, in any case.
     */
    public static function isUuidLike(string $id): bool
    {
        return \strlen($id) === 36 && \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /**
     * Each storage key in `$storageKeys` followed by its wire id when the two differ, so a
     * stored reference in either form matches. A uuid-like form is listed in lower and
     * upper case too, since a text column may keep either. A key the encoder can't encode
     * is kept as it is.
     *
     * @param list<mixed> $storageKeys
     *
     * @return list<mixed>
     */
    public static function forms(?IdEncoderInterface $encoder, array $storageKeys): array
    {
        $forms = [];
        $seen = [];
        foreach ($storageKeys as $key) {
            $candidates = [$key];
            if ($encoder !== null && (\is_scalar($key) || $key instanceof \Stringable)) {
                $candidates[] = $encoder->encode($key);
            }
            foreach ($candidates as $candidate) {
                if (\is_string($candidate) && self::isUuidLike($candidate)) {
                    $candidates[] = \strtolower($candidate);
                    $candidates[] = \strtoupper($candidate);
                }
            }
            foreach ($candidates as $candidate) {
                $seenAs = \is_scalar($candidate) || $candidate instanceof \Stringable ? (string) $candidate : null;
                if ($seenAs !== null && isset($seen[$seenAs])) {
                    continue;
                }
                if ($seenAs !== null) {
                    $seen[$seenAs] = true;
                }
                $forms[] = $candidate;
            }
        }

        return $forms;
    }
}
