<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Schema\Relationship\IdentifiedMember;

/**
 * How an identified {@see MorphToMany}'s link rows map to its members, declared with
 * {@see MorphToManyBuilder::identifiedByRows()}: the class backing the rows, the row field
 * that points at the owning parent, and the row fields holding each member's stored type
 * and id. Every name is a field of the row as its model sees it, an entity property or a
 * model attribute or relation, never a database column.
 *
 * A stored type becomes a JSON:API type through `$typeMap` when one is declared, and is
 * the JSON:API type itself when not. A row whose stored type maps to no declared type,
 * or whose id is empty, is not a member.
 */
final readonly class LinkRowFields
{
    /**
     * @param string                   $through the class backing the link rows, as named with `through()`
     * @param string                   $owner   the row field pointing at the owning parent
     * @param string                   $type    the row field holding the member's stored type
     * @param string                   $id      the row field holding the member's storage key
     * @param list<string>             $types   the relation's declared JSON:API types
     * @param array<array-key, string> $typeMap stored type => JSON:API type; `[]` when the stored type is the JSON:API type
     */
    public function __construct(
        public string $through,
        public string $owner,
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
     * The stored value a new row writes for the JSON:API `$type`: its one `$typeMap` key,
     * or the type itself when no map is declared. The build guarantees a declared map
     * holds exactly one key per declared type.
     *
     * @throws \LogicException when `$type` is not a declared type
     */
    public function storedValueFor(string $type): string
    {
        return $this->storedValues($type)[0]
            ?? throw new \LogicException(\sprintf('The link rows have no stored value for type "%s", which the relation does not declare. Declared types: %s.', $type, \implode(', ', $this->types)));
    }

    /**
     * The member `$row` links to, carrying the row, or `null` when the row is not a
     * member: its stored type maps to no declared type, or its id is missing or empty.
     */
    public function member(mixed $row): ?IdentifiedMember
    {
        $stored = Accessor::get($row, $this->type);
        if ($stored instanceof \BackedEnum) {
            $stored = $stored->value;
        }
        $id = Accessor::get($row, $this->id);
        if ((!\is_string($stored) && !\is_int($stored)) || (!\is_int($id) && (!\is_string($id) || $id === ''))) {
            return null;
        }

        $type = $this->memberType($stored);

        return $type === null ? null : new IdentifiedMember($type, $id, row: $row);
    }

    /**
     * The row fields that break ties after a query's sorts, ascending: the stored type,
     * then the id. A parent links to a member once, so together they order its rows
     * totally.
     *
     * @return list<string>
     */
    public function tiebreak(): array
    {
        return [$this->type, $this->id];
    }
}
