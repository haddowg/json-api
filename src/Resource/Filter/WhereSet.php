<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Filter;

use haddowg\JsonApi\Exception\FilterValueInvalid;
use haddowg\JsonApi\Resource\Constraint\Pattern;

/**
 * Matches rows by whether a nullable column holds a value. `WhereSet::make('archived',
 * 'archivedTs')` answers `filter[archived]=true` with the rows whose `archivedTs` IS NOT
 * NULL and `filter[archived]=false` with the rows where it IS NULL.
 *
 * Its value is always a delimited list of booleans ({@see AcceptsList}), so
 * `filter[archived]=true,false` and `filter[archived][]=true&filter[archived][]=false`
 * ask for either, which is how a client says "both" once the filter has a default. Each
 * member is a boolean in the `FILTER_VALIDATE_BOOLEAN` vocabulary, `true`/`false`,
 * `1`/`0`, `on`/`off`, `yes`/`no` or the empty string for false, and anything else is a
 * `400` {@see FilterValueInvalid}. {@see match()} reads a value into a
 * {@see WhereSetMatch} a data layer applies.
 *
 * A default is held as a list of booleans, so the OpenAPI document shows `[false]`
 * whether it was declared `false`, `'false'` or `[false]`.
 */
final readonly class WhereSet implements \haddowg\JsonApi\Resource\Through\FiltersThrough, \haddowg\JsonApi\Resource\Deprecatable, DescribedFilter, ExemplifiedFilter, AcceptsList, HasRemovableDefault, TargetsColumn
{
    use \haddowg\JsonApi\Resource\Through\ResolvesThrough;
    use ExposesValueMetadata;

    /**
     * The wire forms of a boolean, the vocabulary `FILTER_VALIDATE_BOOLEAN` reads.
     */
    public const string BOOLEAN_PATTERN = '^\s*(?i:true|false|1|0|on|off|yes|no)\s*$|^\s*$';

    /**
     * @param list<bool>|null                                             $default     the default as a list of booleans, or `null` when none is declared
     * @param list<\haddowg\JsonApi\Resource\Constraint\ConstraintInterface> $constraints each member's value constraints
     */
    public function __construct(
        public string $key,
        public string $column,
        public ?array $default = null,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?string $through = null,
        public ?\haddowg\JsonApi\Resource\Through\RelationPath $resolvedThrough = null,
    ) {}

    public static function make(string $key, ?string $column = null): WhereSetBuilder
    {
        return WhereSetBuilder::make($key, $column);
    }

    /**
     * The constraint every member of the value is validated against.
     */
    public static function booleanConstraint(): Pattern
    {
        return new Pattern(self::BOOLEAN_PATTERN, documentsAs: 'boolean');
    }

    public function key(): string
    {
        return $this->key;
    }

    public function targetColumn(): string
    {
        return $this->column;
    }

    public function listValue(): ListValue
    {
        return ListValue::delimited();
    }

    public function hasDefault(): bool
    {
        return $this->default !== null;
    }

    /**
     * @return list<bool>|null
     */
    public function defaultValue(): ?array
    {
        return $this->default;
    }

    public function withoutDefault(): static
    {
        return $this->rebuilt(['default' => null]);
    }

    /**
     * The distinct booleans a requested value holds, read as a list.
     *
     * @return list<bool>
     *
     * @throws FilterValueInvalid when the value is not a list, or a member is not a boolean
     */
    public function booleans(mixed $value): array
    {
        $booleans = [];
        foreach ($this->listValue()->values($this->key, $value) as $member) {
            $boolean = self::boolean($member)
                ?? throw new FilterValueInvalid($this->key, ['Each value must be a boolean: true or false.']);
            $booleans[$boolean ? 1 : 0] = $boolean;
        }

        return \array_values($booleans);
    }

    /**
     * What a requested value asks of the column.
     *
     * @throws FilterValueInvalid as {@see booleans()} does
     */
    public function match(mixed $value): WhereSetMatch
    {
        $booleans = $this->booleans($value);

        return match (\count($booleans)) {
            0 => WhereSetMatch::Neither,
            2 => WhereSetMatch::Either,
            default => $booleans[0] ? WhereSetMatch::Set : WhereSetMatch::NotSet,
        };
    }

    /**
     * A member read as a boolean, or `null` when it is not one.
     */
    public static function boolean(mixed $member): ?bool
    {
        if (\is_bool($member)) {
            return $member;
        }
        if (!\is_string($member) && !\is_int($member)) {
            return null;
        }

        return \filter_var($member, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
    }

    protected function throughLeaf(): array
    {
        return [\haddowg\JsonApi\Resource\Through\LeafKind::Value, 'column'];
    }

    public function leafValue(mixed $value): mixed
    {
        return $value;
    }
}
