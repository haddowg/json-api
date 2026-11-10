<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * Collects backed-enum schemas into reusable named components while a document is
 * projected. When {@see SchemaProjector} projects into a document it is
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
 * It also holds the **named shapes**: an `Obj`, `OneOf`, `Union`, `Map` or `ArrayList`
 * declared with `component()` registers its read, create and update bodies under its
 * name, and each use references the body for its own context (see
 * {@see referenceShape()}).
 *
 * Without a collector (standalone field projection) the projector emits the enum and
 * every named shape inline unchanged — this collector is only threaded for document
 * projection.
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
     * Each named shape's bodies and the field that first declared it, keyed by the
     * declared name.
     *
     * @var array<string, array{field: string, bodies: array<string, Schema>, names: array<string, string>}>
     */
    private array $shapes = [];

    /**
     * The shape that owns each component name a named shape derives, `<Name>` and any
     * `<Name>Create` / `<Name>Update`.
     *
     * @var array<string, string>
     */
    private array $shapeOwners = [];

    /**
     * The named shape components a use has referenced, keyed by component name.
     *
     * @var array<string, Schema>
     */
    private array $referencedShapes = [];

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
     * Registers the read, create and update bodies of the shape `$field` names `$name`,
     * and returns a `$ref` to the one for `$context`.
     *
     * The read body takes the bare name. A write body identical to it shares that name,
     * and one that differs takes `<Name>Create` or `<Name>Update`, as a type's attributes
     * do. A second field naming the same shape must project the same three bodies, and a
     * derived name may belong to only one shape; either mistake throws.
     *
     * @param array{read: Schema, create: Schema, update: Schema} $bodies
     */
    public function referenceShape(string $name, string $field, array $bodies, RepresentationContext $context): Schema
    {
        $bodies = ['read' => $bodies['read'], 'create' => $bodies['create'], 'update' => $bodies['update']];
        $registered = $this->shapes[$name] ?? null;
        if ($registered === null) {
            $read = $bodies['read']->toArray();
            $names = [
                'read' => $name,
                'create' => $bodies['create']->toArray() === $read ? $name : $name . 'Create',
                'update' => $bodies['update']->toArray() === $read ? $name : $name . 'Update',
            ];
            foreach (\array_unique($names) as $derived) {
                $owner = $this->shapeOwners[$derived] ?? null;
                if ($owner !== null && $owner !== $name) {
                    throw new \LogicException(\sprintf(
                        'Field "%s" names the schema component "%s", whose %s shape takes the name "%s", which the component "%s" already uses. Rename one of them.',
                        $field,
                        $name,
                        $derived === $name ? 'read' : \strtolower(\substr($derived, \strlen($name))),
                        $derived,
                        $owner,
                    ));
                }
            }
            foreach ($names as $derived) {
                $this->shapeOwners[$derived] = $name;
            }
            $registered = $this->shapes[$name] = ['field' => $field, 'bodies' => $bodies, 'names' => $names];
        } else {
            foreach ($bodies as $key => $body) {
                if ($body->toArray() !== $registered['bodies'][$key]->toArray()) {
                    throw new \LogicException(\sprintf(
                        'Fields "%s" and "%s" both name the schema component "%s" but project different shapes for %s. Give one of them another name.',
                        $registered['field'],
                        $field,
                        $name,
                        match ($key) {
                            'read' => 'a response',
                            'create' => 'a create',
                            default => 'an update, as a value written whole, such as a list element, and one an update merges into do',
                        },
                    ));
                }
            }
        }

        $key = match ($context) {
            RepresentationContext::Read => 'read',
            RepresentationContext::Create => 'create',
            RepresentationContext::Update => 'update',
        };
        $component = $registered['names'][$key];
        $this->referencedShapes[$component] = $registered['bodies'][$key];

        return $this->reference($component);
    }

    /**
     * The named shape components a use referenced, keyed by component name.
     *
     * @return array<string, Schema>
     */
    public function shapeComponents(): array
    {
        return $this->referencedShapes;
    }

    /**
     * Every component name a named shape derives, referenced or not, with the field that
     * first declared it, so the document can refuse one a generated component also takes.
     *
     * @return array<string, string>
     */
    public function shapeNames(): array
    {
        $names = [];
        foreach ($this->shapeOwners as $derived => $owner) {
            $names[$derived] = $this->shapes[$owner]['field'];
        }

        return $names;
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
