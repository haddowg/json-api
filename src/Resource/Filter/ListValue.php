<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;

/**
 * How a filter that {@see AcceptsList accepts a list} reads its `filter[<key>]` value.
 * The array form, `filter[<key>][]=a&filter[<key>][]=b`, is always accepted. A
 * delimited list also accepts one string split on {@see $delimiter}, as in
 * `filter[<key>]=a,b`; an undelimited one does not, so a comma stays part of a value.
 */
final readonly class ListValue
{
    /**
     * @param non-empty-string|null $delimiter
     */
    private function __construct(
        public ?string $delimiter,
    ) {}

    /**
     * A list sent as an array or as one string split on `$delimiter`.
     */
    public static function delimited(string $delimiter = ','): self
    {
        if ($delimiter === '') {
            throw new \InvalidArgumentException('A list delimiter must not be empty.');
        }

        return new self($delimiter);
    }

    /**
     * A list sent only as an array, for values that may contain the delimiter.
     */
    public static function arrayOnly(): self
    {
        return new self(null);
    }

    public function isDelimited(): bool
    {
        return $this->delimiter !== null;
    }

    /**
     * The members of a requested value: each element of the array form, or each trimmed
     * token of a delimited string. A value of any other shape is a `400`.
     *
     * @return list<mixed>
     *
     * @throws FilterValueInvalid when the value is a string and the list is not delimited, a member is not a single value, or the array is keyed by name
     */
    public function values(string $key, mixed $value): array
    {
        if (\is_array($value)) {
            $members = [];
            foreach ($value as $index => $member) {
                if (!\is_int($index)) {
                    throw new FilterValueInvalid($key, [\sprintf('Send a list as filter[%s][]=…; a named member is not allowed.', $key)]);
                }
                if (\is_array($member) || \is_object($member)) {
                    throw new FilterValueInvalid($key, ['Each member of the list must be a single value.']);
                }
                $members[] = $member;
            }

            return $members;
        }

        if (\is_string($value)) {
            if ($this->delimiter === null) {
                throw new FilterValueInvalid($key, [\sprintf('Send a list as filter[%s][]=…; a single string is not split.', $key)]);
            }

            return \array_map('\trim', \explode($this->delimiter, $value));
        }

        return [$value];
    }

    /**
     * The members a data layer queries for a value that already passed {@see values()},
     * read leniently: an array as is, a string split on the delimiter when there is one
     * and kept whole when there is not.
     *
     * @return list<mixed>
     */
    public function members(mixed $value): array
    {
        if (\is_array($value)) {
            return \array_values($value);
        }

        if (\is_string($value) && $this->delimiter !== null) {
            return \array_map('\trim', \explode($this->delimiter, $value));
        }

        return [$value];
    }
}
