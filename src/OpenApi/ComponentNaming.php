<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Derives stable, idiomatic PascalCase component-name bases from JSON:API
 * type/member names — the single naming convention shared by the component
 * projection ({@see OpenApiProjector}) and the path projection
 * ({@see OperationProjector}), so a path-side `$ref` always names the exact
 * component the component projection emitted.
 */
final class ComponentNaming
{
    /**
     * A PascalCase component-name base from a JSON:API type/member name (e.g.
     * `blog-post` → `BlogPost`, `author` → `Author`). Non-alphanumeric separators
     * (`-`, `_`, space) split words; an empty result degrades to `Resource`.
     */
    public static function base(string $name): string
    {
        $words = \preg_split('/[^A-Za-z0-9]+/', $name) ?: [];
        $base = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $base .= \ucfirst($word);
        }

        return $base === '' ? 'Resource' : $base;
    }

    /**
     * The `#/components/schemas/<X>` pointer for a schema component.
     */
    public static function schemaRef(string $component): string
    {
        return '#/components/schemas/' . $component;
    }
}
