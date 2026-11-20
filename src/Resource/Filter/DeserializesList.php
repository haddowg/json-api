<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The compared list of a set filter that declares `deserializeUsing()`.
 *
 * @property-read string                                  $key
 * @property-read (\Closure(list<mixed>): list<mixed>)|null $deserialize
 */
trait DeserializesList
{
    /**
     * The values a data layer compares the column against: `$members`, the requested
     * members as the list was read, passed through the declared deserializer when there
     * is one.
     *
     * @param list<mixed> $members
     *
     * @return list<mixed>
     *
     * @throws \LogicException when the deserializer returns something other than an array
     */
    public function comparedValues(array $members): array
    {
        if ($this->deserialize === null) {
            return $members;
        }

        $compared = ($this->deserialize)($members);
        // @phpstan-ignore function.alreadyNarrowedType (the closure's return type is not enforced at runtime)
        if (!\is_array($compared)) {
            throw new \LogicException(\sprintf('The deserializer of filter "%s" returned %s; it must return the list of values to compare.', $this->key, \get_debug_type($compared)));
        }

        return \array_values($compared);
    }
}
