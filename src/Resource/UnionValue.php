<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\Resource\Field\AbstractFieldValue;
use haddowg\JsonApi\Resource\Field\Union;

/**
 * One place in a write body where a {@see Union}'s member applies, as {@see UnionValues}
 * finds it: the value, the member its JSON type selected, and whether the union's
 * `required()` makes it refuse an empty value there. An adapter validates `$value` at
 * `$pointer` against the member's constraints, as it validates any field's, adding its
 * own non-empty rule when {@see $nonEmpty} is set.
 */
final readonly class UnionValue
{
    /**
     * @param string             $pointer  the JSON Pointer of the value, as `/data/attributes/value` or `/data/attributes/value/2`
     * @param Union              $union    the union the value was written to; for a list element, the element union
     * @param AbstractFieldValue $member   the member the value's JSON type selected
     * @param mixed              $value    the value as the request sent it
     * @param bool               $nonEmpty whether the value must not be empty: the union is required in the write's
     *                                     context and the member is its string or list member
     */
    public function __construct(
        public string $pointer,
        public Union $union,
        public AbstractFieldValue $member,
        public mixed $value,
        public bool $nonEmpty,
    ) {}

    /**
     * Whether the value is empty where {@see $nonEmpty} refuses that: `''` or `[]`.
     */
    public function isRefusedAsEmpty(): bool
    {
        return $this->nonEmpty && ($this->value === '' || $this->value === []);
    }
}
