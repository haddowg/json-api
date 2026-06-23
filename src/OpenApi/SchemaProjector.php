<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\EmailFormat;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMax;
use haddowg\JsonApi\Resource\Constraint\ExclusiveMin;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\IpFormat;
use haddowg\JsonApi\Resource\Constraint\Max;
use haddowg\JsonApi\Resource\Constraint\MaxItems;
use haddowg\JsonApi\Resource\Constraint\MaxLength;
use haddowg\JsonApi\Resource\Constraint\MaxProperties;
use haddowg\JsonApi\Resource\Constraint\Min;
use haddowg\JsonApi\Resource\Constraint\MinItems;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\MinProperties;
use haddowg\JsonApi\Resource\Constraint\MultipleOf;
use haddowg\JsonApi\Resource\Constraint\NotIn;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\SlugFormat;
use haddowg\JsonApi\Resource\Constraint\UlidFormat;
use haddowg\JsonApi\Resource\Constraint\UniqueItems;
use haddowg\JsonApi\Resource\Constraint\UrlFormat;
use haddowg\JsonApi\Resource\Constraint\UuidFormat;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Field\Time;

/**
 * Projects core JSON:API type metadata (fields + their declared constraints) into
 * JSON Schema 2020-12 nodes — the dialect OpenAPI 3.1 Schema Objects speak.
 *
 * It is a **pure** projector (no I/O, no Symfony): give it a {@see FieldInterface}
 * or a resource's field inventory and it returns immutable {@see Schema} nodes.
 * It mirrors {@see \haddowg\JsonApi\Validation\SchemaCompiler}'s
 * constraint→keyword mapping but emits a **standalone, OpenAPI-shaped** schema
 * (with `description`/`example`, full nullable handling and the complete resource
 * object), where the compiler emits a body-validation *tightening fragment*.
 *
 * **Lossy-by-design degradation.** A constraint that cannot be expressed as a
 * JSON Schema keyword — {@see When} with an opaque condition, {@see CompareField}
 * (no cross-property comparison in 2020-12), and an {@see After}/{@see Before}/
 * {@see Between} date/time bound (JSON Schema 2020-12 has no standard keyword that
 * bounds a `date-time` *string*) — is never emitted as a wrong keyword; instead a
 * human-readable note is appended to the schema `description`.
 *
 * **Backed enums.** When a field's {@see In} carries an enum class-string, the
 * `enum` schema additionally gains `x-enum-varnames` (case names) and, when the
 * enum is a {@see DescribedEnum}, `x-enum-descriptions` plus a markdown
 * `value → description` table in the `description` — controlled by the
 * {@see EnumDescriptionMode}.
 */
final class SchemaProjector
{
    public function __construct(
        private readonly EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {}

    /**
     * Projects one attribute field to a JSON Schema node. `$creating` selects the
     * create (POST) or update (PATCH) constraint context; the default `false`
     * projects the canonical read representation (all always-on constraints).
     *
     * Pass an {@see EnumComponentCollector} when projecting **into a document**: a
     * backed-enum schema is then hoisted into the collector as a reusable named
     * component (`#/components/schemas/<Enum>`) and replaced inline by a `$ref`
     * (§4.8). Omit it (the default) for standalone projection, where the enum is
     * emitted inline unchanged.
     */
    public function projectField(FieldInterface $field, bool $creating = false, ?EnumComponentCollector $collector = null): Schema
    {
        $schema = $this->typeSchema($field);

        if ($field instanceof Map) {
            $properties = [];
            $required = [];
            foreach ($field->children() as $child) {
                if ($child->isHidden()) {
                    continue;
                }
                $properties[$child->name()] = $this->projectField($child, $creating, $collector);
                // Mirror the top-level attributes projection: a required child
                // populates the Map object's `required` only in the create context
                // (on update an absent member means "no change").
                if ($creating && $this->isRequired($child, $creating)) {
                    $required[] = $child->name();
                }
            }
            $schema = $schema->withProperties($properties)->withRequired($required);
        }

        $notes = [];
        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            $schema = $this->applyConstraint($schema, $constraint, $creating, $notes, $collector);
        }

        $schema = $this->applyNullable($schema, $field, $creating);
        $schema = $this->applyDescription($schema, $field, $notes);

        return $this->applyExample($schema, $field);
    }

    /**
     * Projects a standalone list of value constraints to a JSON Schema node — the
     * entry point the OpenAPI path projection (Slice 3) uses to build a
     * `filter[<key>]` query-parameter's value schema from the filter's declared
     * {@see \haddowg\JsonApi\Resource\Filter\FilterInterface::constraints()}.
     *
     * Unlike {@see projectField()} there is no owning field (no base type, no
     * nullable/description/example), so the schema starts from a permissive node and
     * is narrowed purely by the constraints; a lossy constraint's degradation note is
     * appended to the resulting `description` exactly as for a field. An empty
     * constraint list yields a permissive `{}` (a filter value of any shape).
     *
     * Always projects in the **read** context (a `filter[]` value is a query input,
     * never a create/update body member), so create/update-only constraint contexts
     * that do not apply to a read are skipped.
     *
     * @param iterable<ConstraintInterface> $constraints
     */
    public function projectConstraints(iterable $constraints): Schema
    {
        $schema = Schema::create();
        $notes = [];
        foreach ($constraints as $constraint) {
            if (!$constraint->context()->appliesTo(false)) {
                continue;
            }
            $schema = $this->applyConstraint($schema, $constraint, false, $notes);
        }

        return $this->withNotes($schema, $notes);
    }

    /**
     * Projects a type's **attributes** object schema: a `{type: object,
     * properties: {…}}` over the non-id, non-relationship, non-hidden fields.
     * Write-only fields are excluded from a read projection (`$creating === false`);
     * required fields populate `required` only in the create context.
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectAttributes(iterable $fields, bool $creating = false, ?EnumComponentCollector $collector = null): Schema
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            if (!$this->isAttribute($field) || !$this->appearsInRepresentation($field, $creating)) {
                continue;
            }

            $properties[$field->name()] = $this->projectField($field, $creating, $collector);
            if ($creating && $this->isRequired($field, $creating)) {
                $required[] = $field->name();
            }
        }

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired($required);
    }

    /**
     * Projects a type's **resource object** schema: `type` const, `id`,
     * `attributes` (inline), a permissive `relationships` placeholder (full
     * relationship schemas are a later slice), and the conventional `links`/`meta`
     * containers.
     *
     * This is the **response** resource object (the `data` of the read single /
     * collection envelopes), so it requires both `type` *and* `id` — JSON:API 1.1
     * §7.2 mandates an `id` on every resource object returned from the server (the
     * client-id-on-create policy is modelled separately by the create / update
     * request schemas).
     *
     * The schema carries a `description`: the type's own ($description) when the
     * author declared one, else a generated default naming the type.
     *
     * `$attributesRef` (a `#/components/schemas/…` pointer) makes the `attributes`
     * member a `$ref` to a shared attributes component rather than an inline copy —
     * the caller emits that component once and the read resource object and the
     * update request body reference the same node. Omit it to inline (a standalone
     * projection with no component to share).
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectResourceObject(string $type, iterable $fields, bool $creating = false, ?EnumComponentCollector $collector = null, ?string $description = null, ?string $attributesRef = null): Schema
    {
        $fields = \is_array($fields) ? $fields : \iterator_to_array($fields, false);

        $properties = [
            'type' => Schema::ofType('string')->withConst($type),
            'id' => $this->projectId($fields),
            'attributes' => $attributesRef !== null ? Schema::ref($attributesRef) : $this->projectAttributes($fields, $creating, $collector),
            'relationships' => Schema::ofType('object'),
            'links' => Schema::ofType('object'),
            'meta' => Schema::ofType('object'),
        ];

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired(['type', 'id'])
            ->withDescription($description ?? self::resourceObjectDescription($type));
    }

    /**
     * The generated default description for a type's resource object, used when the
     * author declared none.
     */
    public static function resourceObjectDescription(string $type): string
    {
        return 'An `' . $type . '` resource object.';
    }

    /**
     * The `id` member schema: a string, narrowed by the id field's declared
     * pattern/format where one exists. Falls back to a bare string when there is
     * no {@see Id} field.
     *
     * @param array<FieldInterface> $fields
     */
    private function projectId(array $fields): Schema
    {
        foreach ($fields as $field) {
            if ($field instanceof Id) {
                return $this->projectField($field);
            }
        }

        return Schema::ofType('string');
    }

    /**
     * The base type/format schema for a field, derived structurally from its PHP
     * class (the narrower Date/Time/DateTime order matters — they share a base).
     */
    private function typeSchema(FieldInterface $field): Schema
    {
        $type = match (true) {
            $field instanceof Integer => 'integer',
            $field instanceof Decimal => 'number',
            $field instanceof Boolean => 'boolean',
            $field instanceof ArrayList => 'array',
            $field instanceof ArrayHash, $field instanceof Map => 'object',
            default => 'string',
        };

        $schema = Schema::ofType($type);

        $format = match (true) {
            $field instanceof Date => 'date',
            $field instanceof Time => 'time',
            $field instanceof DateTime => 'date-time',
            default => null,
        };
        if ($format !== null) {
            $schema = $schema->withFormat($format);
        }

        // An ArrayHash exposes an open object; an ArrayList's items default to a
        // permissive schema until an `each()` constraint narrows them.
        if ($field instanceof ArrayHash) {
            $schema = $schema->withAdditionalProperties(Schema::create());
        }
        if ($field instanceof ArrayList) {
            $schema = $schema->withItems(Schema::create());
        }

        return $schema;
    }

    /**
     * Applies one constraint to the field schema, mirroring
     * {@see \haddowg\JsonApi\Validation\SchemaCompiler}. Constraints that cannot
     * be expressed losslessly append a note to `$notes` rather than emitting a
     * keyword.
     *
     * @param list<string> $notes
     */
    private function applyConstraint(Schema $schema, ConstraintInterface $constraint, bool $creating, array &$notes, ?EnumComponentCollector $collector = null): Schema
    {
        switch (true) {
            case $constraint instanceof MinLength: return $schema->withMinLength($constraint->value);
            case $constraint instanceof MaxLength: return $schema->withMaxLength($constraint->value);
            case $constraint instanceof MinItems: return $schema->withMinItems($constraint->value);
            case $constraint instanceof MaxItems: return $schema->withMaxItems($constraint->value);
            case $constraint instanceof UniqueItems: return $schema->withUniqueItems();
            case $constraint instanceof MinProperties: return $schema->withMinProperties($constraint->value);
            case $constraint instanceof MaxProperties: return $schema->withMaxProperties($constraint->value);
            case $constraint instanceof Min: return $schema->withMinimum($constraint->value);
            case $constraint instanceof Max: return $schema->withMaximum($constraint->value);
            case $constraint instanceof ExclusiveMin: return $schema->withExclusiveMinimum($constraint->value);
            case $constraint instanceof ExclusiveMax: return $schema->withExclusiveMaximum($constraint->value);
            case $constraint instanceof MultipleOf: return $schema->withMultipleOf($constraint->value);
            case $constraint instanceof Pattern: return $constraint->documentsAs !== null ? $schema->withType($constraint->documentsAs) : $schema->withPattern($constraint->regex);
            case $constraint instanceof SlugFormat: return $schema->withPattern($constraint->regex);
            case $constraint instanceof UlidFormat: return $schema->withPattern(Id::ULID_FORMAT_PATTERN);
            case $constraint instanceof In: return $this->applyEnum($schema, $constraint, $notes, $collector);
            case $constraint instanceof NotIn: return $schema->withNot(Schema::create()->withEnum($constraint->values));
            case $constraint instanceof EmailFormat: return $schema->withFormat('email');
            case $constraint instanceof UrlFormat: return $schema->withFormat('uri');
            case $constraint instanceof UuidFormat: return $schema->withFormat('uuid');
            case $constraint instanceof IpFormat: return $schema->withFormat($constraint->version === 6 ? 'ipv6' : 'ipv4');
            case $constraint instanceof Each: return $schema->withItems($this->eachSchema($constraint, $creating, $collector));
            case $constraint instanceof AtLeastOneOf: return $schema->withAnyOf($this->atLeastOneOfSchema($constraint, $creating, $collector));
            case $constraint instanceof Sequentially: return $this->applySequentially($schema, $constraint, $creating, $notes, $collector);
            case $constraint instanceof Before: return $this->applyDateBound($schema, $constraint->bound, 'must be before', $notes);
            case $constraint instanceof After: return $this->applyDateBound($schema, $constraint->bound, 'must be after', $notes);
            case $constraint instanceof Between:
                $schema = $this->applyDateBound($schema, $constraint->min, 'must be on or after', $notes);

                return $this->applyDateBound($schema, $constraint->max, 'must be on or before', $notes);
            case $constraint instanceof When:
                $notes[] = 'A conditional rule applies to this value; see the resource documentation.';

                return $schema;
            case $constraint instanceof CompareField:
                $notes[] = \sprintf('Value is compared against the `%s` field (%s).', $constraint->field, $constraint->operator->value);

                return $schema;
            default: return $schema;
        }
    }

    /**
     * Applies an {@see In} constraint's `enum`.
     *
     * Without a `$collector` (standalone field projection) the enum is emitted
     * **inline** on the field schema: the `enum` keyword, plus — when the {@see In}
     * retains a backed-enum class-string — the var-names / descriptions metadata per
     * {@see $enumDescriptionMode}, the markdown `value → description` table being
     * appended to the field's `$notes`.
     *
     * With a `$collector` and a backed-enum class-string, the enum schema is instead
     * **hoisted** into a reusable named component (`#/components/schemas/<Enum>`,
     * deduped on the class-string) carrying its own table-in-`description`, and the
     * field schema becomes a `$ref` to it (§4.8). A non-backed-enum `In` (no
     * class-string) is always inline regardless of the collector.
     *
     * @param In<int|string> $constraint
     * @param list<string>   $notes
     */
    private function applyEnum(Schema $schema, In $constraint, array &$notes, ?EnumComponentCollector $collector = null): Schema
    {
        // An empty value set cannot be expressed as a valid `enum` (2020-12 requires
        // a non-empty array), so emit nothing rather than an invalid `enum: []`.
        if ($constraint->values === []) {
            return $schema;
        }

        $enumClass = $constraint->enumClass;

        // A non-backed-enum `In` (or no collector) stays inline on the field schema.
        if ($enumClass === null) {
            return $schema->withEnum($constraint->values);
        }

        if ($collector === null) {
            return $this->buildEnumSchema($schema->withEnum($constraint->values), $constraint, $enumClass, $notes);
        }

        // Hoist a self-contained component (its own description carries the table) and
        // reference it; the field schema becomes a `$ref`.
        $componentNotes = [];
        $body = $this->buildEnumSchema(Schema::create()->withEnum($constraint->values), $constraint, $enumClass, $componentNotes);
        $body = $this->withNotes($body, $componentNotes);

        $name = $collector->register($enumClass, $body);

        return $collector->reference($name);
    }

    /**
     * Builds the backed-enum schema body: the `x-enum-varnames` / `x-enum-descriptions`
     * extensions (per {@see $enumDescriptionMode}) on `$schema`, appending the markdown
     * `value → description` table to `$notes` when the mode emits descriptions. The
     * `enum` keyword is assumed already set on `$schema` by the caller.
     *
     * @param In<int|string> $constraint
     * @param class-string<\BackedEnum> $enumClass
     * @param list<string>   $notes
     */
    private function buildEnumSchema(Schema $schema, In $constraint, string $enumClass, array &$notes): Schema
    {
        // Var-names (case names) are free for any backed enum, aligned to `values`.
        $varNames = [];
        $byValue = [];
        foreach ($enumClass::cases() as $case) {
            $byValue[$case->value] = $case->name;
        }
        foreach ($constraint->values as $value) {
            $varNames[] = $byValue[$value] ?? (string) $value;
        }

        // Per-value descriptions only when the enum opts in via DescribedEnum.
        $descriptions = [];
        if (\is_subclass_of($enumClass, DescribedEnum::class)) {
            /** @var array<int|string, string> $declared */
            $declared = $enumClass::descriptions();
            foreach ($constraint->values as $value) {
                $descriptions[] = $declared[$value] ?? '';
            }
        }

        if ($this->enumDescriptionMode->emitsExtensions()) {
            $schema = $schema->withExtension('enum-varnames', $varNames);
            if ($this->hasAnyDescription($descriptions)) {
                $schema = $schema->withExtension('enum-descriptions', $descriptions);
            }
        }

        if ($this->enumDescriptionMode->emitsDescription() && $this->hasAnyDescription($descriptions)) {
            $notes[] = $this->enumTable($constraint->values, $descriptions);
        }

        return $schema;
    }

    /**
     * Builds the markdown `value → description` table appended to the schema
     * description (the only enum-doc surface the free CDN renderers display).
     *
     * @param list<int|string> $values
     * @param list<string>     $descriptions
     */
    private function enumTable(array $values, array $descriptions): string
    {
        $lines = ['| Value | Description |', '| --- | --- |'];
        foreach ($values as $i => $value) {
            $description = $descriptions[$i] ?? '';
            $lines[] = \sprintf('| `%s` | %s |', $this->escapeTableCell((string) $value), $this->escapeTableCell($description));
        }

        return \implode("\n", $lines);
    }

    /**
     * Escapes pipes (and flattens newlines) so a value or description can't break
     * the markdown table row it sits in.
     */
    private function escapeTableCell(string $text): string
    {
        return \str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    /**
     * @param list<string> $descriptions
     */
    private function hasAnyDescription(array $descriptions): bool
    {
        foreach ($descriptions as $description) {
            if ($description !== '') {
                return true;
            }
        }

        return false;
    }

    private function eachSchema(Each $each, bool $creating, ?EnumComponentCollector $collector = null): Schema
    {
        $items = Schema::create();
        $notes = [];
        foreach ($each->constraints as $constraint) {
            if ($constraint->context()->appliesTo($creating)) {
                $items = $this->applyConstraint($items, $constraint, $creating, $notes, $collector);
            }
        }

        return $this->withNotes($items, $notes);
    }

    /**
     * @return list<Schema>
     */
    private function atLeastOneOfSchema(AtLeastOneOf $constraint, bool $creating, ?EnumComponentCollector $collector = null): array
    {
        $alternatives = [];
        foreach ($constraint->constraints as $alternative) {
            if (!$alternative->context()->appliesTo($creating)) {
                continue;
            }
            $notes = [];
            $alternatives[] = $this->withNotes($this->applyConstraint(Schema::create(), $alternative, $creating, $notes, $collector), $notes);
        }

        return $alternatives;
    }

    /**
     * Merges a {@see Sequentially}'s wrapped constraints inline (ordering is an
     * execution-only concern, so the schema is their conjunction).
     *
     * @param list<string> $notes
     */
    private function applySequentially(Schema $schema, Sequentially $constraint, bool $creating, array &$notes, ?EnumComponentCollector $collector = null): Schema
    {
        foreach ($constraint->constraints as $inner) {
            if ($inner->context()->appliesTo($creating)) {
                $schema = $this->applyConstraint($schema, $inner, $creating, $notes, $collector);
            }
        }

        return $schema;
    }

    /**
     * Degrades a date/time bound to a human-readable note. JSON Schema 2020-12 has
     * **no standard keyword** that bounds a `date-time` *string* (`minimum`/
     * `maximum` are numeric-only, and `formatMinimum`/`formatMaximum` belong to a
     * non-standard format-extension vocabulary no conformant consumer honours), so
     * a wrong keyword is never emitted — the bound is described in prose instead. A
     * fixed bound surfaces its literal value; a closure bound is noted as dynamic.
     *
     * @param \DateTimeInterface|\Closure(): \DateTimeInterface $bound
     * @param list<string>                                      $notes
     */
    private function applyDateBound(Schema $schema, \DateTimeInterface|\Closure $bound, string $note, array &$notes): Schema
    {
        $notes[] = $bound instanceof \DateTimeInterface
            ? \sprintf('Value %s `%s`.', $note, $bound->format(\DateTimeInterface::ATOM))
            : \sprintf('Value %s a dynamically-resolved date/time bound.', $note);

        return $schema;
    }

    /**
     * Widens the schema's scalar `type` to allow `null` when the field declares
     * {@see Nullable} in this context.
     *
     * A nullable enumerated field also needs `null` in its `enum` list: JSON
     * Schema `enum` is an absolute whitelist that overrides the type union, so
     * without this a declared-nullable enum would reject its own legitimate `null`.
     */
    private function applyNullable(Schema $schema, FieldInterface $field, bool $creating): Schema
    {
        foreach ($field->constraints() as $constraint) {
            if (!($constraint instanceof Nullable) || !$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($schema->hasScalarType()) {
                return $this->allowNullInEnum($schema->asNullable());
            }
            // A hoisted backed-enum field is a bare `$ref` with no scalar `type` to
            // widen; the OAS-3.1 way to make a referenced schema nullable is to
            // union it with the null type.
            if (\is_string($schema->get('$ref'))) {
                return Schema::create()->withAnyOf([$schema, Schema::ofType('null')]);
            }
        }

        return $schema;
    }

    /**
     * Appends `null` to an `enum` list when the schema carries one and does not
     * already include it (so a nullable enumerated field accepts its `null`).
     */
    private function allowNullInEnum(Schema $schema): Schema
    {
        $enum = $schema->get('enum');
        if (!\is_array($enum) || \in_array(null, $enum, true)) {
            return $schema;
        }

        /** @var list<mixed> $enum */
        return $schema->withEnum([...$enum, null]);
    }

    /**
     * Sets the schema `description` from the field's declared description plus any
     * degradation notes accumulated while projecting its constraints.
     *
     * @param list<string> $notes
     */
    private function applyDescription(Schema $schema, FieldInterface $field, array $notes): Schema
    {
        $description = $field->getDescription();

        $parts = [];
        if ($description !== null && $description !== '') {
            $parts[] = $description;
        }
        foreach ($notes as $note) {
            $parts[] = $note;
        }

        return $parts === [] ? $schema : $schema->withDescription(\implode("\n\n", $parts));
    }

    /**
     * Appends only the accumulated notes to a schema's description (used for the
     * synthetic sub-schemas of `each`/`anyOf`, which have no owning field).
     *
     * @param list<string> $notes
     */
    private function withNotes(Schema $schema, array $notes): Schema
    {
        if ($notes === []) {
            return $schema;
        }

        $existing = $schema->description();
        $parts = $existing !== null ? [$existing, ...$notes] : $notes;

        return $schema->withDescription(\implode("\n\n", $parts));
    }

    private function applyExample(Schema $schema, FieldInterface $field): Schema
    {
        return $field->hasExample() ? $schema->withExample($field->getExample()) : $schema;
    }

    /**
     * Whether a field is a plain attribute (not the id, not a relationship).
     */
    private function isAttribute(FieldInterface $field): bool
    {
        return !$field instanceof Id && !$field instanceof RelationInterface && !$field->isHidden();
    }

    /**
     * Whether the field appears in the representation being projected: a write-only
     * field is excluded from a read (non-creating) projection.
     */
    private function appearsInRepresentation(FieldInterface $field, bool $creating): bool
    {
        return $creating || !$field->isWriteOnly();
    }

    private function isRequired(FieldInterface $field, bool $creating): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $constraint->context()->appliesTo($creating)) {
                return true;
            }
        }

        return false;
    }
}
