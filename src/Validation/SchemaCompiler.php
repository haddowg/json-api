<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Validation;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\ProvidesFieldSchema;

/**
 * Compiles a {@see AbstractResource}'s field + constraint metadata into a decoded
 * draft-2020-12 JSON Schema (`stdClass`) that **tightens** the JSON:API base
 * schema for one resource type, in a given create/update context.
 *
 * The output is exactly the shape {@see DocumentValidator::validateRequest()}'s
 * `$additionalSchemas` list takes: it `allOf`-composes with the base/request
 * root, so it only constrains `data.attributes` / `data.relationships` (and the
 * per-context `required` arrays). The attributes object and each `Map` it holds
 * are closed: a member the resource does not declare is rejected. It does **not** restate base members or touch
 * `unevaluatedProperties` (the composite owns that).
 *
 * Internally the schema is assembled as nested PHP arrays and converted to the
 * `stdClass` tree opis consumes once, at the boundary — keeping the builder
 * statically analysable (no dynamic `stdClass` properties).
 *
 * **Context.** `compile($resource, creating: true)` emits the POST schema
 * (`Required`/`requiredOnCreate` → `required`); `creating: false` emits the
 * PATCH schema (no member required; only supplied values constrained). `When` (an opaque closure) and `CompareField` (no
 * cross-property comparison in draft 2020-12) are **skipped** — they do not
 * round-trip to JSON Schema.
 */
final class SchemaCompiler
{
    private readonly SchemaProjector $projector;

    public function __construct()
    {
        $this->projector = new SchemaProjector();
    }

    /**
     * Produces the per-resource JSON Schema fragment for `$resource` in the
     * given context.
     *
     * @param bool $creating true for the create (POST) schema, false for update (PATCH)
     */
    public function compile(AbstractResource $resource, bool $creating): object
    {
        return $this->toObject($this->compileArray($resource, $creating));
    }

    /**
     * @return array<string, mixed>
     */
    private function compileArray(AbstractResource $resource, bool $creating): array
    {
        $attributeProperties = [];
        $attributeRequired = [];
        $relationshipProperties = [];
        $relationshipRequired = [];

        foreach ($resource->allFields() as $field) {
            if ($field instanceof Id || $field->isHidden()) {
                continue;
            }

            if ($field instanceof \haddowg\JsonApi\Resource\Field\RelationInterface) {
                $relationshipProperties[$field->name()] = $this->relationshipSchema($field, $creating);
                if ($this->isRequired($field, $creating)) {
                    $relationshipRequired[] = $field->name();
                }

                continue;
            }

            $attributeProperties[$field->name()] = $this->fieldSchema($field, $creating);
            if ($this->isRequired($field, $creating)) {
                $attributeRequired[] = $field->name();
            }
        }

        $dataProperties = [];

        $attributes = ['type' => 'object', 'additionalProperties' => false];
        if ($attributeProperties !== []) {
            $attributes['properties'] = $attributeProperties;
        }
        if ($creating && $attributeRequired !== []) {
            $attributes['required'] = $attributeRequired;
        }
        $dataProperties['attributes'] = $attributes;

        if ($relationshipProperties !== []) {
            $relationships = ['type' => 'object', 'properties' => $relationshipProperties];
            if ($creating && $relationshipRequired !== []) {
                $relationships['required'] = $relationshipRequired;
            }
            $dataProperties['relationships'] = $relationships;
        }

        return ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => $dataProperties]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldSchema(\haddowg\JsonApi\Resource\Field\FieldInterface $field, bool $creating): array
    {
        // A structured field (an Obj, a OneOf, a list of either, or an application's own
        // ProvidesFieldSchema composite) compiles exactly as the OpenAPI projection
        // describes it, so the two can never disagree about its shape. A child read-only
        // for the write stays optional here, since hydration ignores it rather than
        // rejecting it.
        if ($field instanceof ProvidesFieldSchema || ($field instanceof ArrayList && $field->element() !== null)) {
            return $this->projector->admittingReadOnly(fn(): array => $this->projector->projectField($field, $creating)->toArray());
        }

        $schema = $this->typeSchema($field);

        if ($field instanceof Map) {
            $childProperties = [];
            foreach ($field->children() as $child) {
                $childProperties[$child->name()] = $this->fieldSchema($child, $creating);
            }
            if ($childProperties !== []) {
                $schema['properties'] = $childProperties;
            }
            $schema['additionalProperties'] = false;
        }

        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            $schema = $this->applyConstraint($schema, $constraint, $creating);
        }

        return $this->allowNull($schema, $field, $creating);
    }

    /**
     * @return array<string, mixed>
     */
    private function relationshipSchema(\haddowg\JsonApi\Resource\Field\RelationInterface $relation, bool $creating): array
    {
        // A JSON:API relationship object: { "data": <linkage> }. Constrain the
        // linkage type(s) and a to-many's item bounds; cardinality (object vs array)
        // is governed by the base schema, which already validates relationship shape.
        $identifier = ['type' => 'object'];
        $types = $relation->relatedTypes();
        if ($types !== []) {
            $identifier['properties'] = ['type' => ['enum' => \array_values($types)]];
        }

        if (!$relation->isToMany()) {
            return ['type' => 'object', 'properties' => ['data' => $identifier]];
        }

        $dataSchema = ['type' => 'array', 'items' => $identifier];
        foreach ($relation->constraints() as $constraint) {
            if (($constraint instanceof MinItems || $constraint instanceof MaxItems) && $constraint->context()->appliesTo($creating)) {
                $dataSchema = $this->applyConstraint($dataSchema, $constraint, $creating);
            }
        }

        return ['type' => 'object', 'properties' => ['data' => $dataSchema]];
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSchema(\haddowg\JsonApi\Resource\Field\FieldInterface $field): array
    {
        $type = match (true) {
            $field instanceof Integer => 'integer',
            $field instanceof Decimal => 'number',
            $field instanceof Boolean => 'boolean',
            $field instanceof ArrayList => 'array',
            $field instanceof ArrayHash, $field instanceof Map => 'object',
            default => 'string',
        };

        // A date/time field's `format` follows its configured serialization format, not
        // its class: emitting `date-time` for a value that is not RFC 3339 would reject
        // the very body the hydrator accepts.
        $format = $field instanceof DateTime ? $field->schemaFormat() : null;

        $schema = ['type' => $type];
        if ($format !== null) {
            $schema['format'] = $format;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyConstraint(array $schema, \haddowg\JsonApi\Resource\Constraint\ConstraintInterface $constraint, bool $creating): array
    {
        switch (true) {
            // In owns the enum value set; the composites recurse over their wrapped
            // constraints — none reduces to the plain keyword-merge below.
            case $constraint instanceof In: $schema['enum'] = $constraint->values;
                break;
            case $constraint instanceof Each: $schema['items'] = $this->eachSchema($constraint, $creating);
                break;
            case $constraint instanceof Before: $schema = $this->applyDateBound($schema, $constraint->bound, false);
                break;
            case $constraint instanceof After: $schema = $this->applyDateBound($schema, $constraint->bound, true);
                break;
            case $constraint instanceof Between:
                $schema = $this->applyDateBound($schema, $constraint->min, true);
                $schema = $this->applyDateBound($schema, $constraint->max, false);
                break;
            case $constraint instanceof Sequentially:
                // All wrapped constraints ultimately hold, so merge them into the
                // field's own schema (ordering is an execution-only concern).
                foreach ($constraint->constraints as $inner) {
                    if ($inner->context()->appliesTo($creating)) {
                        $schema = $this->applyConstraint($schema, $inner, $creating);
                    }
                }
                break;
            case $constraint instanceof AtLeastOneOf: $schema['anyOf'] = $this->atLeastOneOfSchema($constraint, $creating);
                break;
                // Every self-describing leaf constraint (length/size/numeric bounds, the
                // format/pattern keywords, `not`) folds its own keyword(s) in through the
                // shared Schema VO — one source of truth with the OpenAPI SchemaProjector.
            case $constraint instanceof ProvidesJsonSchema: $schema = $this->applyProvided($schema, $constraint);
                break;
                // Required/Nullable handled by the caller; When/CompareField intentionally skipped.
            default: break;
        }

        return $schema;
    }

    /**
     * Folds a self-describing constraint's JSON Schema keyword(s) into the field
     * schema array. The constraint builds its fragment on the shared {@see Schema}
     * VO — the single source of truth also consulted by the OpenAPI
     * {@see \haddowg\JsonApi\OpenApi\SchemaProjector} — and {@see Schema::toArray()}
     * yields the keyword map to merge in.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function applyProvided(array $schema, ProvidesJsonSchema $constraint): array
    {
        foreach ($constraint->contribute(Schema::create())->toArray() as $keyword => $value) {
            $schema[$keyword] = $value;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function eachSchema(Each $each, bool $creating): array
    {
        $items = [];
        foreach ($each->constraints as $constraint) {
            if ($constraint->context()->appliesTo($creating)) {
                $items = $this->applyConstraint($items, $constraint, $creating);
            }
        }

        return $items;
    }

    /**
     * Compiles each alternative to its own sub-schema for a JSON Schema `anyOf`.
     *
     * @return list<array<string, mixed>>
     */
    private function atLeastOneOfSchema(AtLeastOneOf $constraint, bool $creating): array
    {
        $alternatives = [];
        foreach ($constraint->constraints as $alternative) {
            if ($alternative->context()->appliesTo($creating)) {
                $alternatives[] = $this->applyConstraint([], $alternative, $creating);
            }
        }

        return $alternatives;
    }

    /**
     * Only fixed (non-closure) bounds round-trip, via draft-2020-12
     * formatMinimum/formatMaximum.
     *
     * @param array<string, mixed> $schema
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     * @return array<string, mixed>
     */
    private function applyDateBound(array $schema, \DateTimeInterface|\Closure $bound, bool $isMinimum): array
    {
        if ($bound instanceof \DateTimeInterface) {
            $key = $isMinimum ? 'formatMinimum' : 'formatMaximum';
            $schema[$key] = $bound->format(\DateTimeInterface::ATOM);
        }

        return $schema;
    }

    /**
     * Widens the field's `type` to allow an explicit `null` when the field
     * declares {@see Nullable} in this context.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function allowNull(array $schema, \haddowg\JsonApi\Resource\Field\FieldInterface $field, bool $creating): array
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Nullable && $constraint->context()->appliesTo($creating)) {
                if (isset($schema['type']) && \is_string($schema['type'])) {
                    $schema['type'] = [$schema['type'], 'null'];
                }

                break;
            }
        }

        return $schema;
    }

    private function isRequired(\haddowg\JsonApi\Resource\Field\FieldInterface $field, bool $creating): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $constraint->context()->appliesTo($creating)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively converts a string-keyed schema array into the `stdClass` tree
     * opis expects, leaving list arrays (e.g. `enum`, `required`) as JSON arrays.
     *
     * @param array<string, mixed> $schema
     */
    private function toObject(array $schema): object
    {
        $object = new \stdClass();
        foreach ($schema as $key => $value) {
            $object->{$key} = $this->convert($value);
        }

        return $object;
    }

    private function convert(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (\array_is_list($value)) {
            return \array_map(fn(mixed $item): mixed => $this->convert($item), $value);
        }

        /** @var array<string, mixed> $value */
        return $this->toObject($value);
    }
}
