<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Where a {@see MorphTo} declared with {@see MorphToBuilder::identifiedByColumns()} keeps
 * its target's identifier: the parent's field holding the stored type and the one holding
 * the target's storage key. Each name is a field of the parent as its model sees it, an
 * entity property or a model attribute, so a data layer can compare them in a query.
 *
 * A stored type becomes a JSON:API type through `$typeMap` when one is declared, and is
 * the JSON:API type itself when not.
 */
final readonly class MorphColumns
{
    /**
     * @param string                   $type    the parent field holding the target's stored type
     * @param string                   $id      the parent field holding the target's storage key
     * @param list<string>             $types   the relation's declared JSON:API types
     * @param array<array-key, string> $typeMap stored type => JSON:API type; `[]` when the stored type is the JSON:API type
     */
    public function __construct(
        public string $type,
        public string $id,
        public array $types,
        public array $typeMap = [],
    ) {}

    /**
     * The JSON:API type a stored type value names, or `null` when it names none of the
     * relation's declared types.
     */
    public function memberType(int|string $stored): ?string
    {
        $stored = (string) $stored;
        if ($this->typeMap !== []) {
            return $this->typeMap[$stored] ?? null;
        }

        return \in_array($stored, $this->types, true) ? $stored : null;
    }

    /**
     * The stored values that name the JSON:API `$type`: each `$typeMap` key mapping to it,
     * or the type itself when no map is declared.
     *
     * @return list<string>
     */
    public function storedValues(string $type): array
    {
        if ($this->typeMap === []) {
            return \in_array($type, $this->types, true) ? [$type] : [];
        }

        $values = [];
        foreach ($this->typeMap as $stored => $mapped) {
            if ($mapped === $type) {
                $values[] = (string) $stored;
            }
        }

        return $values;
    }

    /**
     * The stored value a write keeps in the type field for the JSON:API `$type`: the
     * first `$typeMap` key mapping to it, or the type itself when no map is declared.
     *
     * @throws \LogicException when `$type` is not a declared type
     */
    public function storedValueFor(string $type): string
    {
        return $this->storedValues($type)[0]
            ?? throw new \LogicException(\sprintf('The type field "%s" has no stored value for type "%s", which the relation does not declare. Declared types: %s.', $this->type, $type, \implode(', ', $this->types)));
    }

    /**
     * The `[type, id]` the parent stores, with the stored type mapped to its JSON:API
     * type, or `null` when the relation is empty: no stored type, or no id.
     *
     * @return array{0: string, 1: string|int}|null
     *
     * @throws \LogicException when the stored type names none of the declared types
     */
    public function pair(mixed $parent): ?array
    {
        $stored = Accessor::get($parent, $this->type);
        if ($stored instanceof \BackedEnum) {
            $stored = $stored->value;
        }
        $id = Accessor::get($parent, $this->id);
        // A key held as a value object, such as a Uuid, is its string form.
        if ($id instanceof \Stringable) {
            $id = (string) $id;
        }
        if ((!\is_string($stored) && !\is_int($stored)) || $stored === '' || (!\is_int($id) && (!\is_string($id) || $id === ''))) {
            return null;
        }

        $type = $this->memberType($stored);
        if ($type === null) {
            throw new \LogicException(\sprintf(
                'The stored type "%s" in field "%s" names none of the declared types (%s)%s.',
                (string) $stored,
                $this->type,
                \implode(', ', $this->types),
                $this->typeMap === [] ? '' : ' through the declared typeMap',
            ));
        }

        return [$type, $id];
    }
}
