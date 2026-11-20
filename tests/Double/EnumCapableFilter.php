<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Filter\AcceptsList;
use haddowg\JsonApi\Resource\Filter\DescribedFilter;
use haddowg\JsonApi\Resource\Filter\HasValueConstraints;
use haddowg\JsonApi\Resource\Filter\ListValue;

/**
 * A self-contained readonly custom filter built on {@see HasValueConstraints}, optionally
 * reading its value as a list.
 */
final readonly class EnumCapableFilter implements DescribedFilter, AcceptsList
{
    use HasValueConstraints;

    /**
     * @param list<ConstraintInterface> $constraints
     */
    public function __construct(
        public string $key,
        public ?ListValue $list = null,
        public array $constraints = [],
        public ?string $description = null,
        public bool $hasExample = false,
        public mixed $example = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function listValue(): ?ListValue
    {
        return $this->list;
    }

    /**
     * @param list<ConstraintInterface> $constraints
     */
    protected function withConstraints(array $constraints): static
    {
        return new self($this->key, $this->list, $constraints, $this->description, $this->hasExample, $this->example);
    }

    protected function withDescriptionAndExample(?string $description, bool $hasExample, mixed $example): static
    {
        return new self($this->key, $this->list, $this->constraints, $description, $hasExample, $example);
    }
}
