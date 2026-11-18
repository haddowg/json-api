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
     * The name an operation's own components start with: the type's base and the operation
     * kind (`ArticlesUpdate`), with the relation's base for a related or relationship
     * operation (`ArticlesAuthorFetchRelated`), the action's path and `Action` for an action
     * (`ArticlesPublishAction`, followed by the method when `$methodQualified`), and
     * `AtomicOperations` for the Atomic Operations endpoint. Its error documents add the
     * status, its document with typed meta adds `Document`.
     */
    public static function operation(string $type, Metadata\OperationKey $key, bool $methodQualified = false): string
    {
        return match (true) {
            $key->kind === Metadata\OperationKind::Atomic => 'AtomicOperations',
            $key->kind === Metadata\OperationKind::Action => self::base($type) . self::base((string) $key->actionPath) . 'Action'
                . ($methodQualified ? self::base(\strtolower($key->method)) : ''),
            $key->relation !== null => self::base($type) . self::base($key->relation) . \ucfirst($key->kind->value),
            default => self::base($type) . \ucfirst($key->kind->value),
        };
    }

    /**
     * The `#/components/schemas/<X>` pointer for a schema component.
     */
    public static function schemaRef(string $component): string
    {
        return '#/components/schemas/' . $component;
    }

    /**
     * The component a raw response's schema class is emitted under: its short class name.
     *
     * @param class-string<Metadata\DescribesRawBody> $class
     */
    public static function rawBody(string $class): string
    {
        $offset = \strrpos($class, '\\');

        return $offset === false ? $class : \substr($class, $offset + 1);
    }
}
