<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Collects backed-enum schemas into reusable named components while a document is
 * projected (§4.8). When {@see SchemaProjector} projects into a document it is
 * given a collector: each enum schema sourced from an {@see
 * \haddowg\JsonApi\Resource\Constraint\In} carrying an enum class-string is
 * **hoisted** here once (deduped on the full class-string) and replaced inline by a
 * `$ref` to `#/components/schemas/<Name>`.
 *
 * The component name is the enum's **short class name**; two enums sharing a short
 * name in different namespaces are disambiguated with a numeric suffix
 * (`Status`, `Status2`, …) so a name is always stable for a given class-string. A
 * set of **reserved names** (the document's already-generated component names —
 * `Meta`, `Links`, `Error`, …) may be supplied to the constructor: an enum whose
 * short name collides with one is disambiguated the same way, so a realistically
 * named enum (e.g. `Meta`) can never overwrite a generated component.
 *
 * Without a collector (standalone field projection) the projector emits the enum
 * inline unchanged — this collector is only threaded for document projection.
 */
final class EnumComponentCollector
{
    /**
     * The collected component schemas, keyed by component name.
     *
     * @var array<string, Schema>
     */
    private array $components = [];

    /**
     * The component name already assigned to each enum class-string (the dedup map).
     *
     * @var array<string, string>
     */
    private array $namesByClass = [];

    /**
     * Reserved component names a hoisted enum must not shadow, held as a set.
     *
     * @var array<string, true>
     */
    private array $reservedNames;

    /**
     * @param list<string> $reservedNames component names already taken in the
     *                                     document (e.g. the shared/generated ones) that
     *                                     a hoisted enum must not overwrite
     */
    public function __construct(array $reservedNames = [])
    {
        $this->reservedNames = \array_fill_keys($reservedNames, true);
    }

    /**
     * Registers `$schema` as the component body for `$enumClass` (idempotent — a
     * repeat call for the same class returns the existing name without overwriting),
     * and returns the component name to `$ref`. `$enumClass` is the enum's
     * fully-qualified class-string (the dedup key + short-name source).
     */
    public function register(string $enumClass, Schema $schema): string
    {
        if (isset($this->namesByClass[$enumClass])) {
            return $this->namesByClass[$enumClass];
        }

        $name = $this->uniqueName($enumClass);
        $this->namesByClass[$enumClass] = $name;
        $this->components[$name] = $schema;

        return $name;
    }

    /**
     * A `$ref` schema pointing at the named component.
     */
    public function reference(string $name): Schema
    {
        return Schema::ref('#/components/schemas/' . $name);
    }

    /**
     * The collected enum component schemas, keyed by component name.
     *
     * @return array<string, Schema>
     */
    public function components(): array
    {
        return $this->components;
    }

    /**
     * Derives a collision-free component name from the enum's short class name,
     * suffixing a counter while the candidate is already taken — either by a
     * *different* collected enum or by a reserved (already-generated) component name.
     */
    private function uniqueName(string $enumClass): string
    {
        $position = \strrpos($enumClass, '\\');
        $base = $position === false ? $enumClass : \substr($enumClass, $position + 1);

        $name = $base;
        $suffix = 1;
        while (isset($this->components[$name]) || isset($this->reservedNames[$name])) {
            ++$suffix;
            $name = $base . $suffix;
        }

        return $name;
    }
}
