<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

/**
 * The `acceptsList()` declaration shared by the set filter builders.
 */
trait BuildsListValue
{
    private ?bool $listDelimited = null;

    /**
     * Declares the value a list ({@see AcceptsList}). The array form
     * `filter[<key>][]=a&filter[<key>][]=b` is always accepted; pass `delimited: false`
     * when a member may contain the delimiter, so a single delimited string is refused
     * rather than split.
     */
    public function acceptsList(bool $delimited = true): static
    {
        $this->listDelimited = $delimited;

        return $this;
    }

    private function builtListValue(?string $delimiter): ?ListValue
    {
        return match ($this->listDelimited) {
            null => null,
            true => ListValue::delimited($delimiter !== null && $delimiter !== '' ? $delimiter : ','),
            false => ListValue::arrayOnly(),
        };
    }
}
