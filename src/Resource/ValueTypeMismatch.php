<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * One value in a write body whose JSON type its field does not take, as
 * {@see ValueTypes} finds it. An adapter reports it as the only violation at its pointer.
 */
final readonly class ValueTypeMismatch
{
    /**
     * @param string         $pointer  the JSON Pointer of the value, as `/data/attributes/rows/0/Key`
     * @param FieldInterface $field    the field the value was written to; for an element of a list of
     *                                 scalars, the list
     * @param string         $expected the JSON type the value must have, one of the {@see ValueTypes} constants,
     *                                 or for a {@see Field\Union} its members' types joined by `|`
     * @param mixed          $value    the value as the request sent it, `null` when the field is not nullable
     */
    public function __construct(
        public string $pointer,
        public FieldInterface $field,
        public string $expected,
        public mixed $value,
    ) {}

    /**
     * Whether the value is `null` sent to a field that is not nullable, which an adapter's
     * own nullability rule may already report.
     */
    public function isNull(): bool
    {
        return $this->value === null;
    }

    /**
     * The JSON types the value may have: one, or each member type of a union.
     *
     * @return list<string>
     */
    public function expectedTypes(): array
    {
        return \explode('|', $this->expected);
    }

    /**
     * Whether a violation at `$pointer` is about this value or something inside it, and so
     * gives way to the mismatch.
     */
    public function covers(string $pointer): bool
    {
        return $pointer === $this->pointer || \str_starts_with($pointer, $this->pointer . '/');
    }
}
