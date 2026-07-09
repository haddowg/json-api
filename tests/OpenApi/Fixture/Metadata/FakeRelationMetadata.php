<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Schema;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * An in-core {@see RelationMetadataInterface} fixture — a plain value carrier so the
 * projector tests need no Symfony.
 */
final class FakeRelationMetadata implements RelationMetadataInterface
{
    /**
     * @param list<string>          $relatedTypes
     * @param list<FilterInterface> $filters
     * @param list<SortInterface>   $sorts
     * @param list<string>          $relatedIncludablePaths
     * @param list<FieldInterface>  $pivotFields
     */
    public function __construct(
        private readonly string $name,
        private readonly array $relatedTypes,
        private readonly bool $toMany,
        private readonly ?string $description = null,
        private readonly bool $includable = true,
        private readonly bool $relatedEndpoint = true,
        private readonly bool $relationshipEndpoint = true,
        private readonly bool $allowsReplace = true,
        private readonly bool $allowsAdd = true,
        private readonly bool $allowsRemove = true,
        private readonly bool $countable = false,
        private readonly ?Schema $pageSchema = null,
        private readonly array $filters = [],
        private readonly array $sorts = [],
        private readonly array $relatedIncludablePaths = [],
        private readonly string|bool|null $securityRead = null,
        private readonly string|bool|null $securityMutate = null,
        private readonly array $pivotFields = [],
    ) {}

    /**
     * @param list<string> $relatedTypes
     */
    public static function toOne(string $name, array $relatedTypes, ?string $description = null): self
    {
        return new self($name, $relatedTypes, false, $description);
    }

    /**
     * @param list<string>         $relatedTypes
     * @param list<FieldInterface> $pivotFields a pivot-backed (belongsToMany) relation's per-edge fields
     */
    public static function toMany(string $name, array $relatedTypes, ?string $description = null, array $pivotFields = []): self
    {
        return new self($name, $relatedTypes, true, $description, countable: true, pivotFields: $pivotFields);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function relatedTypes(): array
    {
        return $this->relatedTypes;
    }

    public function isToMany(): bool
    {
        return $this->toMany;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isIncludable(): bool
    {
        return $this->includable;
    }

    public function exposesRelatedEndpoint(): bool
    {
        return $this->relatedEndpoint;
    }

    public function exposesRelationshipEndpoint(): bool
    {
        return $this->relationshipEndpoint;
    }

    public function allowsReplace(): bool
    {
        return $this->allowsReplace;
    }

    public function allowsAdd(): bool
    {
        return $this->allowsAdd;
    }

    public function allowsRemove(): bool
    {
        return $this->allowsRemove;
    }

    public function isCountable(): bool
    {
        return $this->countable;
    }

    public function securityRead(): string|bool|null
    {
        return $this->securityRead;
    }

    public function securityMutate(): string|bool|null
    {
        return $this->securityMutate;
    }

    public function pageSchema(): ?Schema
    {
        return $this->pageSchema;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function sorts(): array
    {
        return $this->sorts;
    }

    public function relatedIncludablePaths(): array
    {
        return $this->relatedIncludablePaths;
    }

    public function pivotFields(): array
    {
        return $this->pivotFields;
    }
}
