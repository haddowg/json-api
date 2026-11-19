<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Internal\JsonObject;

/**
 * One declared `meta` object: its members, built, and the {@see AdditionalProperties}
 * entry that opens it, if any. A type's request and resource meta, an action's request
 * meta, a pivot's fields and an `identifierMeta()` list are each read as one, so every
 * `meta` object follows one rule: closed by default, open when the entry is present.
 *
 * Adapters and the OpenAPI projection share it. On a write an adapter finds the members
 * the object does not declare with {@see undeclared()} and the values of the wrong JSON
 * type with {@see mismatches()}, runs each declared member's constraints itself, and then
 * reads the typed values with {@see values()} and applies the `fillUsing()` hooks with
 * {@see fill()}. On a read it renders the members a hook supplies with {@see extract()}
 * and, in debug only, checks the rendered object with {@see violations()}.
 *
 * A declaration with neither members nor the entry is empty: its object stays open in
 * both directions and nothing is checked.
 */
final readonly class MetaShape
{
    /**
     * @param list<FieldInterface> $fields the declared members, built
     */
    private function __construct(
        public array $fields,
        public ?AdditionalProperties $additional,
        public string $owner,
    ) {}

    /**
     * Reads a declaration: field builders or built fields, at most one of them an
     * {@see AdditionalProperties} entry.
     *
     * @param iterable<FieldInterface|FieldBuilderInterface> $declaration
     * @param class-string<MetaMisdeclared>                   $error       the exception a misdeclaration raises
     *
     * @throws MetaMisdeclared when a member is declared twice, a member takes the entry's name, or two entries are declared
     */
    public static function of(iterable $declaration, string $owner = 'the meta object', string $error = MetaMisdeclared::class): self
    {
        $fields = [];
        $additional = null;
        foreach ($declaration as $field) {
            $field = $field instanceof FieldBuilderInterface ? $field->build() : $field;
            if ($field instanceof AdditionalProperties) {
                if ($additional !== null) {
                    throw new $error(\sprintf('The meta of %s declares more than one additionalProperties entry; declare at most one.', $owner));
                }
                $additional = $field;

                continue;
            }
            $name = $field->name();
            if ($name === AdditionalProperties::NAME) {
                throw new $error(\sprintf('The meta of %s declares a member named "%s", the name of the additionalProperties entry. Use AdditionalProperties::make() to open the object.', $owner, $name));
            }
            if (isset($fields[$name])) {
                throw new $error(\sprintf('The meta of %s declares the member "%s" twice.', $owner, $name));
            }
            $fields[$name] = $field;
        }

        return new self(\array_values($fields), $additional, $owner);
    }

    /**
     * An empty declaration, whose object stays open and unchecked.
     */
    public static function none(string $owner = 'the meta object'): self
    {
        return new self([], null, $owner);
    }

    /**
     * Whether nothing is declared, members or entry, so the object stays open and unchecked.
     */
    public function isEmpty(): bool
    {
        return $this->fields === [] && $this->additional === null;
    }

    /**
     * Whether the {@see AdditionalProperties} entry opens the object.
     */
    public function isOpen(): bool
    {
        return $this->additional !== null;
    }

    /**
     * The declared member named `$name`, if any.
     */
    public function field(string $name): ?FieldInterface
    {
        foreach ($this->fields as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The declared members a write may set: every member not hidden and not read-only in
     * the write's context, judged for `$request` when one is known.
     *
     * @return list<FieldInterface>
     */
    public function writableFields(bool $creating, ?JsonApiRequestInterface $request = null): array
    {
        $writable = [];
        foreach ($this->fields as $field) {
            $readOnly = $request === null ? $field->isReadOnly($creating) : $field->isReadOnlyFor($creating, $request);
            if (!$field->isHidden() && !$readOnly) {
                $writable[] = $field;
            }
        }

        return $writable;
    }

    /**
     * Whether a written object may carry members the declaration does not name.
     */
    public function acceptsUndeclared(): bool
    {
        return $this->isEmpty() || $this->isOpen();
    }

    /**
     * A written object with each number put in the PHP type its declared member, or the
     * entry's value field, documents, as {@see WireNumbers} does for attributes: a whole
     * `5.0` where an `Integer` is declared becomes `5`. An adapter validates the normalized
     * object; {@see values()} and {@see fill()} normalize it themselves. A value of the
     * wrong JSON type is left for validation to refuse.
     *
     * @return ($values is array ? array<array-key, mixed> : mixed)
     */
    public function normalize(mixed $values): mixed
    {
        if ($this->isEmpty() || !\is_array($values)) {
            return $values;
        }

        $of = $this->additional?->of();
        foreach ($values as $name => $value) {
            $field = $this->field((string) $name) ?? $of;
            if ($field !== null) {
                $values[$name] = WireNumbers::value($field, $value);
            }
        }

        return $values;
    }

    /**
     * The JSON Pointer of every member of a written object the declaration does not name,
     * at any depth a declared member's shape reaches. `$pointer` locates the object, such as
     * `/meta` or `/data/meta`. A declared member read-only for the write is not undeclared:
     * it is ignored. Open objects take every member the entry's value field accepts; an
     * empty declaration reports nothing.
     *
     * @param array<array-key, mixed> $values the object as sent
     *
     * @return list<string>
     */
    public function undeclared(array $values, string $pointer): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $of = $this->additional?->of();
        if ($this->isOpen() && $of === null) {
            $declared = [];
            foreach ($this->fields as $field) {
                $declared[$field->name()] = true;
            }

            return UndeclaredAttributeMembers::under($this->fields, \array_intersect_key($values, $declared), $pointer);
        }

        return UndeclaredAttributeMembers::under($this->fields, $values, $pointer, $of);
    }

    /**
     * Every value of a written object whose JSON type its declared member, or the entry's
     * value field, does not take, each at its own pointer below `$pointer`.
     *
     * @param array<array-key, mixed> $values the object as sent
     *
     * @return list<ValueTypeMismatch>
     */
    public function mismatches(array $values, string $pointer, bool $creating, ?JsonApiRequestInterface $request = null): array
    {
        $found = [];
        $declared = [];
        foreach ($this->writableFields($creating, $request) as $field) {
            $declared[$field->name()] = true;
            if (\array_key_exists($field->name(), $values)) {
                $found = [...$found, ...ValueTypes::mismatchesOf($field, $values[$field->name()], $pointer . '/' . JsonObject::pointerSegment($field->name()), $creating, $request)];
            }
        }

        $of = $this->additional?->of();
        if ($of !== null) {
            foreach ($this->additionalMembers($values) as $name => $value) {
                $found = [...$found, ...ValueTypes::mismatchesOf($of, $value, $pointer . '/' . JsonObject::pointerSegment($name), $creating, $request)];
            }
        }

        return $found;
    }

    /**
     * The members of a written object the declaration does not name, which the entry takes.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function additionalMembers(array $values): array
    {
        if ($this->additional === null) {
            return [];
        }

        $members = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if ($this->field($name) === null) {
                $members[$name] = $value;
            }
        }

        return $members;
    }

    /**
     * The typed values of a written object, after the adapter validated it: each writable
     * declared member sent, cast as its field casts an attribute, a backed enum included,
     * and each member the entry takes, cast by its value field when it has one. A read-only
     * or undeclared member is left out. An empty declaration hands back the object as sent.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    public function values(array $values, bool $creating, ?JsonApiRequestInterface $request = null): array
    {
        $values = $this->normalize($values);
        if ($this->isEmpty()) {
            $sent = [];
            foreach ($values as $name => $value) {
                $sent[(string) $name] = $value;
            }

            return $sent;
        }

        $typed = [];
        foreach ($this->writableFields($creating, $request) as $field) {
            $name = $field->name();
            if (\array_key_exists($name, $values)) {
                $typed[$name] = $values[$name] === null ? null : ($field instanceof AbstractFieldValue ? $field->castWireValueFor($values[$name], $creating) : $field->castWireValue($values[$name]));
            }
        }

        if ($this->additional !== null) {
            $typed = [...$typed, ...$this->additional->cast($this->additionalMembers($values))];
        }

        return $typed;
    }

    /**
     * Applies a written object to `$model`: each writable declared member sent whose field
     * declares `fillUsing()` is handed to that hook, and the members the entry takes go to
     * the entry's `fillUsing()` together. Members with no hook stay on the written meta for
     * the write's hooks to read, and never reach the model.
     *
     * @param array<array-key, mixed> $values the object as sent
     */
    public function fill(mixed $model, array $values, bool $creating, JsonApiRequestInterface $request): mixed
    {
        $values = $this->normalize($values);
        $data = [];
        foreach ($values as $name => $value) {
            $data[(string) $name] = $value;
        }

        foreach ($this->writableFields($creating, $request) as $field) {
            if ($field instanceof AbstractFieldValue && $field->fillsThroughHook() && \array_key_exists($field->name(), $data)) {
                $model = $field->hydrate($model, $data[$field->name()], $data, $request, $creating);
            }
        }

        if ($this->additional !== null && $this->additional->fills()) {
            $members = $this->additionalMembers($data);
            if ($members !== []) {
                $model = $this->additional->fill($model, $members, $data);
            }
        }

        return $model;
    }

    /**
     * The members a read renders from `$model`: each declared member an `extractUsing()`
     * hook supplies, through its declared shape, then the extra members the entry's hook
     * returns. An extra member never replaces a declared one.
     *
     * @return array<string, mixed>
     */
    public function extract(mixed $model, JsonApiRequestInterface $request): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            if ($field instanceof AbstractFieldValue && $field->extractsThroughHook() && !$field->isWriteOnly() && !$field->isHiddenFor($request, $model)) {
                $values[$field->name()] = $field->serialize($model, $request, $field->name());
            }
        }

        if ($this->additional !== null && !$this->additional->isWriteOnly()) {
            foreach ($this->additional->extract($model, $request) as $name => $value) {
                if (!\array_key_exists($name, $values) && $this->field($name) === null) {
                    $values[$name] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Whether a read renders anything through a hook, so a renderer may skip the walk.
     */
    public function extractsAny(): bool
    {
        foreach ($this->fields as $field) {
            if ($field instanceof AbstractFieldValue && $field->extractsThroughHook()) {
                return true;
            }
        }

        return $this->additional !== null && $this->additional->extractsThroughHook();
    }

    /**
     * What is wrong with a rendered object against the declaration: a member declared
     * `required()` missing, a member it does not declare on a closed object, or a value of
     * the wrong JSON type, an extra member's checked against the entry's value field. A
     * member in `$alsoAllowed`, such as a collection's `page`, is always allowed. Empty when
     * it conforms, and always for an empty declaration.
     *
     * @param array<array-key, mixed> $rendered
     * @param list<string>            $alsoAllowed
     *
     * @return list<string>
     */
    public function violations(array $rendered, array $alsoAllowed = []): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $schemas = new SchemaProjector();
        $violations = [];
        $declared = [];
        foreach ($this->fields as $field) {
            $name = $field->name();
            $declared[$name] = true;
            if (!\array_key_exists($name, $rendered)) {
                if ($schemas->isRequired($field, true)) {
                    $violations[] = \sprintf('the required member "%s" is missing', $name);
                }
                continue;
            }

            $violation = self::typeViolation($schemas, $field, $name, $rendered[$name]);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        $of = $this->additional?->of();
        foreach ($rendered as $name => $value) {
            $name = (string) $name;
            if (isset($declared[$name]) || \in_array($name, $alsoAllowed, true)) {
                continue;
            }
            if ($this->additional === null) {
                $violations[] = \sprintf('the member "%s" is not declared', $name);

                continue;
            }
            $violation = $of === null ? null : self::typeViolation($schemas, $of, $name, $value);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * The projected schema of the object, under the read rules when `$writing` is false and
     * for a write in the given context otherwise.
     */
    public function schema(SchemaProjector $schemas, bool $writing = false, bool $creating = true, ?\haddowg\JsonApi\OpenApi\EnumComponentCollector $collector = null): Schema
    {
        return $schemas->projectMetaObject($this, $writing, $creating, $collector);
    }

    private static function typeViolation(SchemaProjector $schemas, FieldInterface $field, string $name, mixed $value): ?string
    {
        $schema = $schemas->projectDocumentMeta([$field])->get('properties');
        $schema = \is_array($schema) ? \array_values($schema)[0] ?? null : null;
        $expected = $schema instanceof Schema ? self::jsonTypes($schema->toArray()) : null;
        if ($expected === null) {
            return null;
        }
        $actual = self::jsonType($value);

        return self::matches($actual, $expected)
            ? null
            : \sprintf('the member "%s" is %s, not %s', $name, $actual === 'empty' ? 'an empty array' : $actual, \implode(' or ', $expected));
    }

    /**
     * The JSON types a projected schema allows, or `null` when it does not say.
     *
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>|null
     */
    private static function jsonTypes(array $schema): ?array
    {
        $type = $schema['type'] ?? null;
        if (\is_string($type)) {
            return [$type];
        }
        if (\is_array($type)) {
            return \array_values(\array_filter($type, \is_string(...)));
        }

        $branches = $schema['anyOf'] ?? $schema['oneOf'] ?? $schema['allOf'] ?? null;
        if (!\is_array($branches)) {
            return null;
        }
        $types = [];
        foreach ($branches as $branch) {
            $branchTypes = \is_array($branch) ? self::jsonTypes($branch) : null;
            if ($branchTypes === null) {
                return null;
            }
            $types = [...$types, ...$branchTypes];
        }

        return \array_values(\array_unique($types));
    }

    private static function jsonType(mixed $value): string
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'boolean',
            \is_int($value) => 'integer',
            \is_float($value) => 'number',
            \is_string($value) => 'string',
            \is_array($value) => $value === [] ? 'empty' : (\array_is_list($value) ? 'array' : 'object'),
            default => 'object',
        };
    }

    /**
     * @param list<string> $expected
     */
    private static function matches(string $actual, array $expected): bool
    {
        return match ($actual) {
            'empty' => \in_array('array', $expected, true) || \in_array('object', $expected, true),
            'integer' => \in_array('integer', $expected, true) || \in_array('number', $expected, true),
            default => \in_array($actual, $expected, true),
        };
    }
}
