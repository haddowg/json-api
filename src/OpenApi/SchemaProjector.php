<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\Resource\Constraint\After;
use haddowg\JsonApi\Resource\Constraint\AtLeastOneOf;
use haddowg\JsonApi\Resource\Constraint\Before;
use haddowg\JsonApi\Resource\Constraint\Between;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Context;
use haddowg\JsonApi\Resource\Constraint\Each;
use haddowg\JsonApi\Resource\Constraint\In;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Constraint\ProvidesJsonSchema;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\Sequentially;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Enum\DescribedEnum;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\ProvidesFieldSchema;
use haddowg\JsonApi\Resource\Field\RelationInterface;

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
 * human-readable note is appended to the schema `description`. The same rule governs
 * the `format` keyword of a {@see DateTime} field: `date-time`, `date` and `time` are
 * RFC 3339 productions, so a field configured to serialize anything else is documented
 * as a plain string whose shape is given by example (see {@see temporalShapeNotes()}).
 *
 * **Backed enums.** When a field's {@see In} carries an enum class-string, the
 * `enum` schema additionally gains `x-enum-varnames` (case names) and, when the
 * enum is a {@see DescribedEnum}, `x-enum-descriptions` plus a markdown
 * `value → description` table in the `description` — controlled by the
 * {@see EnumDescriptionMode}.
 */
final class SchemaProjector
{
    /**
     * Set while a read (response) representation is being projected, so a nested
     * {@see ProvidesFieldSchema} composite recursing through {@see projectField()} with
     * `$creating = false` still gets read-context constraint resolution.
     */
    private bool $reading = false;

    public function __construct(
        private readonly EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {}

    /**
     * Projects one attribute field to a JSON Schema node. `$creating` selects the
     * create (POST) or update (PATCH) constraint context; the default `false`
     * projects the update context. Inside a read projection ({@see projectAttributes()}
     * with {@see RepresentationContext::Read}, {@see projectResourceObject()}) a value
     * keyword is kept only when every write that can set the field enforces it (see
     * {@see narrowingScope()}), and `null` is admitted unless the server guarantees a
     * value (see {@see applyNullability()}).
     *
     * Pass an {@see EnumComponentCollector} when projecting **into a document**: a
     * backed-enum schema is then hoisted into the collector as a reusable named
     * component (`#/components/schemas/<Enum>`) and replaced inline by a `$ref`
     * (§4.8). Omit it (the default) for standalone projection, where the enum is
     * emitted inline unchanged.
     */
    public function projectField(FieldInterface $field, bool $creating = false, ?EnumComponentCollector $collector = null): Schema
    {
        // A field that describes its own schema (a composite type — Obj, the
        // discriminated union) supplies the base node; the projector still layers the
        // common post-processing (constraints, nullable, description, example) below.
        if ($field instanceof ProvidesFieldSchema) {
            $schema = $field->projectFieldSchema($this, $creating, $collector);

            return $this->applyFieldPostProcessing($schema, $field, $creating, $collector);
        }

        $schema = $field instanceof Map
            ? $this->projectNestedObject($field, $field->children(), $creating, $collector)
            : $this->typeSchema($field);

        return $this->applyFieldPostProcessing($schema, $field, $creating, $collector);
    }

    /**
     * Projects a nested object's children ({@see Map}, {@see Obj}, or an application's own
     * {@see ProvidesFieldSchema} composite) to a `{type: object, properties, required}`
     * node, following the same per-representation rules as {@see projectAttributes()}:
     *
     * - **Create** lists each child a create must supply.
     * - **Update** lists none, since an absent member means "no change".
     * - **Read** lists every child the parent always renders: all of them except a
     *   write-only one, a `hidden(when:)` / `writeOnly(when:)` one, and every child of a
     *   parent whose `serializeUsing()` / `extractUsing()` hook produces its own shape. A
     *   write-only child is left out of the read `properties` too.
     *
     * A hidden child is never projected. Presence says nothing about `null`; the child's
     * own schema does.
     *
     * @param iterable<FieldInterface> $children
     */
    public function projectNestedObject(FieldInterface $parent, iterable $children, bool $creating, ?EnumComponentCollector $collector = null): Schema
    {
        $reading = $this->reading && !$creating;
        $authorShaped = $parent instanceof AbstractFieldValue && $parent->rendersThroughHook();
        $properties = [];
        $required = [];
        foreach ($children as $child) {
            if ($child->isHidden() || ($reading && $child->isWriteOnly())) {
                continue;
            }
            $properties[$child->name()] = $this->projectField($child, $creating, $collector);

            $isRequired = match (true) {
                $creating => $this->isRequired($child, true),
                $reading => !$authorShaped && !$child->hasConditionalReadVisibility(),
                default => false,
            };
            if ($isRequired) {
                $required[] = $child->name();
            }
        }

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired($required);
    }

    /**
     * Whether a read (response) representation is being projected, so a
     * {@see ProvidesFieldSchema} composite can tell a read from an update, which both
     * reach it with `$creating = false`.
     */
    public function isReading(): bool
    {
        return $this->reading;
    }

    /**
     * Layers the field-level post-processing common to every field — the applying
     * constraints, then nullability, description (with any lossy-constraint notes)
     * and example — onto a base schema, whether that base came from the built-in
     * type switch or a {@see ProvidesFieldSchema} composite.
     */
    private function applyFieldPostProcessing(Schema $schema, FieldInterface $field, bool $creating, ?EnumComponentCollector $collector): Schema
    {
        // A list's `each()` items compose on top of its declared element type
        // (default `string`); non-list fields never carry an Each constraint.
        $itemType = $field instanceof ArrayList ? $field->elementType() : 'string';

        $applies = $this->narrowingScope($field, $creating);
        $notes = $this->temporalShapeNotes($field);
        foreach ($field->constraints() as $constraint) {
            if (!$applies($constraint->context())) {
                continue;
            }
            $schema = $this->applyConstraint($schema, $constraint, $applies, $notes, $collector, $itemType);
        }

        $schema = $this->applyRequiredNonEmpty($schema, $field, $applies);
        $schema = $this->applyNullability($schema, $field, $creating);
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
     * A query value has no create/update context, so every constraint projects
     * whatever {@see Context} it carries. A filter validator enforces them all.
     *
     * @param iterable<ConstraintInterface> $constraints
     */
    public function projectConstraints(iterable $constraints): Schema
    {
        $schema = Schema::create();
        $notes = [];
        $always = static fn(Context $context): bool => true;
        foreach ($constraints as $constraint) {
            $schema = $this->applyConstraint($schema, $constraint, $always, $notes);
        }

        return $this->withNotes($schema, $notes);
    }

    /**
     * Projects a type's **attributes** object schema: a `{type: object,
     * properties: {…}}` over the non-id, non-relationship, non-hidden fields.
     * Write-only fields are excluded from a read projection (`$creating === false`).
     *
     * The `required` array is populated by context:
     * - **Create** — the fields a POST body must carry (a PATCH is partial, so an
     *   update never marks a member required; a response has no request-required notion).
     * - **Read** — the members **guaranteed present** in a response that names no
     *   `fields[<type>]`: every read attribute except one whose presence is
     *   request-conditional ({@see FieldInterface::hasConditionalReadVisibility()} — a
     *   `hidden(when:)` / `writeOnly(when:)` field stays in this superset schema but may
     *   be absent for some requests) and one that is sparse by default (rendered only when
     *   a fieldset names it). A consumer then knows a required read member is always there
     *   and a non-required one may be `undefined`. Presence says nothing about `null`;
     *   the member's own schema does.
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectAttributes(iterable $fields, RepresentationContext $context = RepresentationContext::Read, ?EnumComponentCollector $collector = null): Schema
    {
        $creating = $context === RepresentationContext::Create;
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            if (!$this->isAttribute($field) || !$this->appearsInRepresentation($field, $context)) {
                continue;
            }

            $properties[$field->name()] = $context === RepresentationContext::Read
                ? $this->reading(fn(): Schema => $this->projectField($field, false, $collector))
                : $this->projectField($field, $creating, $collector);

            if ($context === RepresentationContext::Create) {
                if ($this->isRequired($field, true)) {
                    $required[] = $field->name();
                }
            } elseif ($context === RepresentationContext::Read && !$field->hasConditionalReadVisibility() && !$field->isSparseByDefault()) {
                $required[] = $field->name();
            }
        }

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired($required);
    }

    /**
     * The **read-representation attribute member names** for a type's fields: every
     * attribute that appears in a response. Excludes `id`, relations, write-only fields
     * and unconditionally-hidden fields; a *conditionally*-hidden field is **included**
     * (it belongs to the read superset — it may appear for some requests). This is the
     * selectable-member vocabulary for a `fields[<type>]` sparse-fieldset parameter, and
     * mirrors exactly the members {@see projectAttributes()} emits in the Read context.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<string>
     */
    public function readAttributeNames(iterable $fields): array
    {
        $names = [];
        foreach ($fields as $field) {
            if ($this->isAttribute($field) && $this->appearsInRepresentation($field, RepresentationContext::Read)) {
                $names[] = $field->name();
            }
        }

        return $names;
    }

    /**
     * Projects a `belongsToMany` relation's **request** `meta.pivot` object: the pivot fields a
     * relationship mutation or an embedded write may send. A field no write can set is left
     * out, and a create-required field is listed in `required` so a new member must carry it
     * (matching the runtime's `422`). Constraints project in the update context, since one
     * object serves both attaching a member and replacing a set.
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectPivotObject(iterable $fields, ?EnumComponentCollector $collector = null): Schema
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            $appearsInCreate = $this->appearsInRepresentation($field, RepresentationContext::Create);
            if ($field->isHidden() || (!$appearsInCreate && !$this->appearsInRepresentation($field, RepresentationContext::Update))) {
                continue;
            }
            $properties[$field->name()] = $this->projectField($field, false, $collector);
            if ($appearsInCreate && $this->isRequired($field, true)) {
                $required[] = $field->name();
            }
        }

        return Schema::ofType('object')
            ->withProperties($properties)
            ->withRequired($required);
    }

    /**
     * Projects a `belongsToMany` relation's **response** `meta.pivot` object: every pivot field a
     * rendered linkage identifier carries, read-only ones included and write-only ones left out.
     * Each field follows the read rules an attribute does, so it admits `null` unless the server
     * guarantees a value (ADR 0061), and `required` lists every field rendered on every response,
     * leaving out a `hidden(when:)` / `writeOnly(when:)` one.
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectReadPivotObject(iterable $fields, ?EnumComponentCollector $collector = null): Schema
    {
        return $this->projectReadObject($fields, $collector);
    }

    /**
     * Projects a set of fields a response renders as one object, such as a pivot's
     * `meta.pivot` or a declared link's `meta`, under the read rules
     * {@see projectReadPivotObject()} describes.
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectReadObject(iterable $fields, ?EnumComponentCollector $collector = null): Schema
    {
        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            if ($field->isHidden() || !$this->appearsInRepresentation($field, RepresentationContext::Read)) {
                continue;
            }
            $properties[$field->name()] = $this->reading(fn(): Schema => $this->projectField($field, false, $collector));
            if (!$field->hasConditionalReadVisibility()) {
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
     * This is always the **read** (response) representation, so `attributes` projects
     * in the {@see RepresentationContext::Read} context. `$attributesRef` (a
     * `#/components/schemas/…` pointer) makes the `attributes` member a `$ref` to the
     * shared read-attributes component instead of an inline copy; omit it to inline
     * (a standalone projection with no component to share).
     *
     * @param iterable<FieldInterface> $fields
     */
    public function projectResourceObject(string $type, iterable $fields, ?EnumComponentCollector $collector = null, ?string $description = null, ?string $attributesRef = null): Schema
    {
        $fields = \is_array($fields) ? $fields : \iterator_to_array($fields, false);

        $properties = [
            'type' => Schema::ofType('string')->withConst($type),
            'id' => $this->projectId($fields),
            'attributes' => $attributesRef !== null ? Schema::ref($attributesRef) : $this->projectAttributes($fields, RepresentationContext::Read, $collector),
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
     * author declared none. `$singular` names one resource of the type in prose; when
     * omitted it is inflected from the type name.
     */
    public static function resourceObjectDescription(string $type, ?string $singular = null): string
    {
        $noun = TypeNoun::fromType($type, $singular)->withArticle();

        return \ucfirst($noun) . ' resource object.';
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
                return $this->reading(fn(): Schema => $this->projectField($field));
            }
        }

        return Schema::ofType('string');
    }

    /**
     * The JSON type a field's value carries when it has a **scalar** wire form —
     * `string`, `integer`, `number` or `boolean` — or `null` when it has none.
     *
     * This is the field's base type with nothing layered on it: no `format`, no
     * `enum`, no length or bound from its constraints. Those narrowings describe the
     * member in a document body, where the whole value is the field's; they do not
     * survive the move to a `filter[<key>]`, whose value is an operand the filter's
     * operator compares (a substring match against an enum column takes neither the
     * enum nor its length). The OpenAPI path projection reads this to default a
     * filter's value schema to the type of the field it targets.
     *
     * A composite ({@see Map}, {@see ArrayHash}, {@see ArrayList}, or a
     * {@see ProvidesFieldSchema} type that supplies its own node) and a relation have
     * no scalar form, so each returns `null` rather than a type that would misdescribe
     * a query-string value.
     */
    public function scalarValueType(FieldInterface $field): ?string
    {
        if ($field instanceof RelationInterface
            || $field instanceof ProvidesFieldSchema
            || $field instanceof Map
            || $field instanceof ArrayHash
            || $field instanceof ArrayList) {
            return null;
        }

        $type = $this->typeSchema($field)->get('type');

        return \is_string($type) && $type !== 'object' && $type !== 'array' ? $type : null;
    }

    /**
     * The base type/format schema for a field, derived structurally from its PHP
     * class. The `format` keyword of a date/time field is the exception: it comes
     * from the field's configured serialization format rather than its class, since
     * that format is what decides whether an RFC 3339 keyword is true.
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

        $format = $field instanceof DateTime ? $field->schemaFormat() : null;
        if ($format !== null) {
            $schema = $schema->withFormat($format);
        }

        // An ArrayHash exposes an open object; an ArrayList's items carry the declared
        // element type (default `string`, so a list never degrades to an untyped
        // `unknown[]`), which any `each()` constraint then narrows further.
        if ($field instanceof ArrayHash) {
            $schema = $schema->withAdditionalProperties(Schema::create());
        }
        if ($field instanceof ArrayList) {
            $schema = $schema->withItems(Schema::ofType($field->elementType()));
        }

        return $schema;
    }

    /**
     * Applies one constraint to the field schema, mirroring
     * {@see \haddowg\JsonApi\Validation\SchemaCompiler}. Constraints that cannot
     * be expressed losslessly append a note to `$notes` rather than emitting a
     * keyword.
     *
     * @param \Closure(Context): bool $applies  whether a nested constraint's context applies
     * @param list<string>             $notes
     * @param string                   $itemType the JSON type an {@see Each} constraint's item schema
     *                                           starts from (a list's declared element type; default
     *                                           `string`)
     */
    private function applyConstraint(Schema $schema, ConstraintInterface $constraint, \Closure $applies, array &$notes, ?EnumComponentCollector $collector = null, string $itemType = 'string'): Schema
    {
        switch (true) {
            // A Pattern carrying an OpenAPI-only `documentsAs` type overrides its own
            // `pattern` keyword (a `pattern` is meaningless on a non-string schema);
            // otherwise it self-describes like any other leaf constraint.
            case $constraint instanceof Pattern: return $constraint->documentsAs !== null ? $schema->withType($constraint->documentsAs) : $constraint->contribute($schema);
                // In carries enum var-names / descriptions and can hoist a backed enum into
                // a reusable component — projector-only concerns, so it is not a plain leaf.
            case $constraint instanceof In: return $this->applyEnum($schema, $constraint, $notes, $collector);
            case $constraint instanceof Each: return $schema->withItems($this->eachSchema($constraint, $applies, $itemType, $collector));
            case $constraint instanceof AtLeastOneOf: return $schema->withAnyOf($this->atLeastOneOfSchema($constraint, $collector));
            case $constraint instanceof Sequentially: return $this->applySequentially($schema, $constraint, $applies, $notes, $collector);
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
                // Every self-contained leaf constraint (length/size/numeric bounds, the
                // format/pattern/enum keywords) describes its own JSON Schema keyword.
            case $constraint instanceof ProvidesJsonSchema: return $constraint->contribute($schema);
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

    /**
     * @param \Closure(Context): bool $applies
     */
    private function eachSchema(Each $each, \Closure $applies, string $itemType = 'string', ?EnumComponentCollector $collector = null): Schema
    {
        // Start from the list's declared element type so items carry a JSON type (never
        // an untyped `unknown[]`); the per-item constraints narrow it from there.
        $items = Schema::ofType($itemType);
        $notes = [];
        foreach ($each->constraints as $constraint) {
            if ($applies($constraint->context())) {
                $items = $this->applyConstraint($items, $constraint, $applies, $notes, $collector);
            }
        }

        return $this->withNotes($items, $notes);
    }

    /**
     * Every alternative projects whatever its own {@see Context}: an `anyOf` that
     * dropped one would reject values the server accepts through it.
     *
     * @return list<Schema>
     */
    private function atLeastOneOfSchema(AtLeastOneOf $constraint, ?EnumComponentCollector $collector = null): array
    {
        $always = static fn(Context $context): bool => true;
        $alternatives = [];
        foreach ($constraint->constraints as $alternative) {
            $notes = [];
            $alternatives[] = $this->withNotes($this->applyConstraint(Schema::create(), $alternative, $always, $notes, $collector), $notes);
        }

        return $alternatives;
    }

    /**
     * Merges a {@see Sequentially}'s wrapped constraints inline (ordering is an
     * execution-only concern, so the schema is their conjunction).
     *
     * @param \Closure(Context): bool $applies
     * @param list<string>             $notes
     */
    private function applySequentially(Schema $schema, Sequentially $constraint, \Closure $applies, array &$notes, ?EnumComponentCollector $collector = null): Schema
    {
        foreach ($constraint->constraints as $inner) {
            if ($applies($inner->context())) {
                $schema = $this->applyConstraint($schema, $inner, $applies, $notes, $collector);
            }
        }

        return $schema;
    }

    /**
     * Degrades a date/time field whose configured serialization format is not RFC 3339
     * to a human-readable note.
     *
     * `date-time`, `date` and `time` are each defined as an RFC 3339 production, so a
     * server that writes anything else cannot be given one of those keywords without
     * misdirecting every consumer that reads it — a generated client coerces on the
     * keyword and gets a parse failure, or a wrong-shaped value, from a response the
     * document called valid. The field is documented as the plain string it is, and the
     * shape it does take is described by example. No `pattern` is derived: a PHP format
     * string can render variable-width fields and open-ended timezone names, so a
     * regex inferred from one would eventually reject a response the server is entitled
     * to send, which is worse for a consumer than no regex at all.
     *
     * @return list<string>
     */
    private function temporalShapeNotes(FieldInterface $field): array
    {
        if (!$field instanceof DateTime || $field->schemaFormat() !== null) {
            return [];
        }

        return [\sprintf(
            'A date/time value written as `%s`. No standard `format` keyword describes that shape, so parse it literally rather than as an RFC 3339 date-time.',
            $field->sampleWireValue(),
        )];
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
     * Admits `null` into the field's schema when the representation can carry it.
     *
     * A request body admits it when the field declares {@see Nullable} in that context,
     * directly or inside a {@see When} (a conditional `null` is accepted for some
     * requests, so the superset schema admits it).
     *
     * A response admits it unless the server guarantees a value. The id always has one.
     * A value is guaranteed when the field promises it ({@see readGuarantee()}), or when
     * it renders what was written and every create had to supply it: an unconditional
     * {@see Required} on create, on a field a create can write. Even then a write that
     * accepts `null` lets it in, as for a request body. Everything else may render `null`:
     * an optional field a create left out, a read-only field the server may not have
     * populated, a value flattened through a to-one chain with a null hop.
     */
    private function applyNullability(Schema $schema, FieldInterface $field, bool $creating): Schema
    {
        if ($creating || !$this->reading || $field instanceof Id) {
            return $this->declaresNullable($field, $this->wideningScope($field, $creating)) ? $this->admitNull($schema) : $schema;
        }

        return match ($this->readGuarantee($field)) {
            true => $schema,
            false => $this->admitNull($schema),
            null => !$this->requiredOnEveryCreate($field) || $this->declaresNullable($field, $this->wideningScope($field, false))
                ? $this->admitNull($schema)
                : $schema,
        };
    }

    /**
     * What the field promises about its rendered value, see
     * {@see AbstractFieldValue::readValueGuarantee()}. A field that does not extend it
     * makes no promise beyond its write constraints, except that a value flattened
     * through `on()` can always be `null`.
     */
    private function readGuarantee(FieldInterface $field): ?bool
    {
        if ($field instanceof AbstractFieldValue) {
            return $field->readValueGuarantee();
        }

        return $field->relatedVia() !== null ? false : null;
    }

    /**
     * Whether every create must supply the field: an unconditional {@see Required} that
     * applies on create, on a field a create can write. A `Required` inside a
     * {@see When} holds for only some requests, so it does not count.
     */
    private function requiredOnEveryCreate(FieldInterface $field): bool
    {
        if ($field->isReadOnly(true)) {
            return false;
        }

        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $constraint->context()->onCreate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the field declares {@see Nullable} under `$applies`, directly or inside a
     * {@see When}.
     *
     * @param \Closure(Context): bool $applies
     */
    private function declaresNullable(FieldInterface $field, \Closure $applies): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($this->declaresPresenceRule($constraint, Nullable::class, $applies)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Widens a schema to admit `null`.
     *
     * A nullable enumerated field also needs `null` in its `enum` list: JSON
     * Schema `enum` is an absolute whitelist that overrides the type union, so
     * without this a nullable enum would reject its own legitimate `null`.
     */
    private function admitNull(Schema $schema): Schema
    {
        // A hoisted backed-enum field is a bare `$ref` with no scalar `type` to
        // widen; the OAS-3.1 way to make a referenced schema nullable is to
        // union it with the null type.
        if (\is_string($schema->get('$ref'))) {
            return Schema::create()->withAnyOf([$schema, Schema::ofType('null')]);
        }

        if ($schema->hasScalarType()) {
            $schema = $this->allowNullInEnum($schema->asNullable());
        }

        // A combinator must also admit `null` explicitly: widening the type
        // union is not enough, because `oneOf`/`anyOf`/`allOf` apply regardless
        // of the `type` keyword (a nullable Shape'd field would otherwise
        // document a schema that rejects the null it accepts at runtime).
        $oneOf = $schema->get('oneOf');
        if (\is_array($oneOf)) {
            /** @var list<Schema> $oneOf */
            return $schema->withOneOf([...$oneOf, Schema::ofType('null')]);
        }
        $anyOf = $schema->get('anyOf');
        if (\is_array($anyOf)) {
            /** @var list<Schema> $anyOf */
            return $schema->withAnyOf([...$anyOf, Schema::ofType('null')]);
        }
        $allOf = $schema->get('allOf');
        if (\is_array($allOf)) {
            /** @var list<Schema> $allOf */
            // A conjunction cannot gain a null branch — hoist it into a
            // disjunction with the null type instead.
            return $schema->without('allOf')
                ->withAnyOf([Schema::ofType('null'), Schema::create()->withAllOf($allOf)]);
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
     * field is excluded from a read (response) body; a field read-only in the
     * projected write context is excluded from that request body (a create can differ
     * from an update via `readOnlyOnCreate()` / `readOnlyOnUpdate()`).
     */
    private function appearsInRepresentation(FieldInterface $field, RepresentationContext $context): bool
    {
        return match ($context) {
            RepresentationContext::Read => !$field->isWriteOnly(),
            RepresentationContext::Create => !$field->isReadOnly(true),
            RepresentationContext::Update => !$field->isReadOnly(false),
        };
    }

    /**
     * Runs `$projection` as part of a read (response) projection, restoring the
     * previous mode afterwards.
     *
     * @template T
     *
     * @param \Closure(): T $projection
     *
     * @return T
     */
    private function reading(\Closure $projection): mixed
    {
        $previous = $this->reading;
        $this->reading = true;

        try {
            return $projection();
        } finally {
            $this->reading = $previous;
        }
    }

    /**
     * Which constraint contexts narrow the field's schema.
     *
     * A request body takes the constraints of its own context. A response value was
     * written by a create or an update, so a keyword describes it only when every write
     * that can set the field enforces it: a create-only `min()` on a field an update can
     * also set holds for nothing the update wrote. A field no write can set falls back
     * to the constraints that apply on both.
     *
     * @return \Closure(Context): bool
     */
    private function narrowingScope(FieldInterface $field, bool $creating): \Closure
    {
        if ($creating || !$this->reading) {
            return static fn(Context $context): bool => $context->appliesTo($creating);
        }

        [$onCreate, $onUpdate] = $this->writableContexts($field);

        return static fn(Context $context): bool => (!$onCreate || $context->onCreate) && (!$onUpdate || $context->onUpdate);
    }

    /**
     * Which constraint contexts let `null` into the field's schema: a request body's own
     * context, or, for a response, any write that can set the field.
     *
     * @return \Closure(Context): bool
     */
    private function wideningScope(FieldInterface $field, bool $creating): \Closure
    {
        if ($creating || !$this->reading) {
            return static fn(Context $context): bool => $context->appliesTo($creating);
        }

        [$onCreate, $onUpdate] = $this->writableContexts($field);

        return static fn(Context $context): bool => ($onCreate && $context->onCreate) || ($onUpdate && $context->onUpdate);
    }

    /**
     * @return array{bool, bool} whether a create and an update can write the field
     */
    private function writableContexts(FieldInterface $field): array
    {
        $onCreate = !$field->isReadOnly(true);
        $onUpdate = !$field->isReadOnly(false);

        return $onCreate || $onUpdate ? [$onCreate, $onUpdate] : [true, true];
    }

    /**
     * Whether `$constraint` is a `$rule` presence marker applying under `$applies`,
     * either directly or wrapped in a {@see When} (the conditional form a validator
     * honours for the requests its condition admits).
     *
     * @param class-string<ConstraintInterface> $rule
     * @param \Closure(Context): bool           $applies
     */
    private function declaresPresenceRule(ConstraintInterface $constraint, string $rule, \Closure $applies): bool
    {
        if (!$applies($constraint->context())) {
            return false;
        }
        if ($constraint instanceof $rule) {
            return true;
        }
        if ($constraint instanceof When) {
            foreach ($constraint->constraints as $inner) {
                if ($inner instanceof $rule && $applies($inner->context())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Projects the non-empty half of an unconditional {@see Required}: a required value
     * may not be an empty string, list or object, in either context (absence is the
     * other half, carried by the object's `required` list). A boolean or number has no
     * keyword for it, and a `Required` inside a {@see When} applies only to some
     * requests, so neither narrows the schema.
     *
     * @param \Closure(Context): bool $applies
     */
    private function applyRequiredNonEmpty(Schema $schema, FieldInterface $field, \Closure $applies): Schema
    {
        $required = false;
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $applies($constraint->context())) {
                $required = true;

                break;
            }
        }
        if (!$required) {
            return $schema;
        }

        $keyword = match ($schema->get('type')) {
            'string' => 'minLength',
            'array' => 'minItems',
            'object' => 'minProperties',
            default => null,
        };
        if ($keyword === null) {
            return $schema;
        }

        $current = $schema->get($keyword);
        if (\is_int($current) && $current >= 1) {
            return $schema;
        }

        return match ($keyword) {
            'minLength' => $schema->withMinLength(1),
            'minItems' => $schema->withMinItems(1),
            'minProperties' => $schema->withMinProperties(1),
        };
    }

    /**
     * Whether the field carries a {@see Required} constraint applying in the given
     * context. Public so a self-describing composite ({@see ProvidesFieldSchema})
     * can populate its object `required` from its children; prefer
     * {@see projectNestedObject()}, which also covers the read representation.
     */
    public function isRequired(FieldInterface $field, bool $creating): bool
    {
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof Required && $constraint->context()->appliesTo($creating)) {
                return true;
            }
        }

        return false;
    }
}
