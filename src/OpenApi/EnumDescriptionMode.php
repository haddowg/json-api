<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * How the {@see SchemaProjector} surfaces a backed enum's per-value descriptions.
 *
 * The free CDN renderers (Swagger UI, ReDoc CE) show only the schema
 * `description`; structured `x-enum-varnames` / `x-enum-descriptions` are
 * codegen-portable but not rendered by those tools. The default {@see Both}
 * emits each form so neither audience is short-changed.
 */
enum EnumDescriptionMode: string
{
    /**
     * Emit both the markdown `value → description` table in the schema
     * `description` and the `x-enum-varnames` / `x-enum-descriptions` extensions.
     */
    case Both = 'both';

    /**
     * Emit only the `x-enum-varnames` / `x-enum-descriptions` extensions.
     */
    case Extensions = 'extensions';

    /**
     * Emit only the markdown `value → description` table in the schema
     * `description`.
     */
    case Description = 'description';

    public function emitsExtensions(): bool
    {
        return $this === self::Both || $this === self::Extensions;
    }

    public function emitsDescription(): bool
    {
        return $this === self::Both || $this === self::Description;
    }
}
