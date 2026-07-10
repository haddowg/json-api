<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A generic string attribute — the built, readonly value object the engine walks.
 * Authors declare one with {@see make()}, which returns a mutable
 * {@see StrBuilder}; the resource **builds** it into this value object before use.
 *
 * The builder's `email()` / `url()` / `uuid()` / `slug()` / `ip()` shortcuts (and
 * the {@see Email} / {@see Url} / {@see Uuid} / {@see Slug} / {@see Ip} facades)
 * append a format constraint and build a plain `Str`, so
 * `Str::make('contact')->email()` and `Email::make('contact')` produce identical
 * metadata.
 */
final readonly class Str extends AbstractFieldValue
{
    public static function make(string $name): StrBuilder
    {
        return new StrBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        return $raw === null ? null : (\is_scalar($raw) ? (string) $raw : $raw);
    }
}
