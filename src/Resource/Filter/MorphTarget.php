<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PolymorphicRelationInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;

/**
 * A {@see WhereMorph}'s relation resolved against the type it filters, by
 * {@see ThroughPathResolver}: the polymorphic relation itself, its declared types, and
 * each type's id field, through whose encoder {@see storageId()} decodes a wire id.
 */
final readonly class MorphTarget
{
    /**
     * @param list<string>           $types the relation's declared types
     * @param array<string, Id|null> $ids   each declared type's id field, `null` when the type is not a registered resource or declares none
     */
    public function __construct(
        public PolymorphicRelationInterface $relation,
        public array $types,
        public array $ids,
    ) {}

    /**
     * The constraints each id of `$type` is validated against: the type's declared id
     * format, so a numeric id validates as an integer and a UUID id as a UUID.
     *
     * @return list<ConstraintInterface>
     */
    public function idConstraints(string $type): array
    {
        $id = $this->ids[$type] ?? null;

        return $id === null ? [] : ThroughPath::idFormatConstraints($id);
    }

    /**
     * The storage key a wire id of `$type` names: decoded through the type's id encoder
     * when it declares one, and the wire id unchanged when it does not.
     *
     * @throws FilterValueInvalid when the encoder cannot decode the id
     */
    public function storageId(string $key, string $type, string|int $wireId): mixed
    {
        $encoder = ($this->ids[$type] ?? null)?->encoder();
        if ($encoder === null) {
            return $wireId;
        }

        return $encoder->decode((string) $wireId)
            ?? throw new FilterValueInvalid($key . '][id', [\sprintf('"%s" is not the id of a "%s" resource.', $wireId, $type)]);
    }

    /**
     * The values a stored reference to the targets of `$type` with the storage keys
     * `$storageKeys` may hold: each key and, when the type's id encoder renders it
     * differently, its wire id. A polymorphic id column may hold either.
     *
     * @param list<mixed> $storageKeys
     *
     * @return list<mixed>
     */
    public function storedForms(string $type, array $storageKeys): array
    {
        return StoredIdForms::forms(($this->ids[$type] ?? null)?->encoder(), $storageKeys);
    }

    /**
     * The wire id of `$type` a stored reference names, whichever form it holds.
     */
    public function wireId(string $type, string|int $stored): string
    {
        return StoredIdForms::wireId(($this->ids[$type] ?? null)?->encoder(), $stored);
    }
}
