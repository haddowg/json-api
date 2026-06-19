<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * An in-core {@see TypeMetadataInterface} fixture — a plain value carrier so the
 * projector tests need no Symfony. The named constructors model the two shapes the
 * contract must tolerate: a {@see resource()} with a field inventory, and a
 * {@see standalone()} serializer-only type with none.
 */
final class FakeTypeMetadata implements TypeMetadataInterface
{
    /**
     * @param list<FieldInterface>            $fields
     * @param list<RelationMetadataInterface> $relations
     * @param list<OperationType>             $operations
     * @param list<OperationType>             $securedOperations
     * @param list<FilterInterface>           $filters
     * @param list<SortInterface>             $sorts
     * @param list<ActionMetadataInterface>   $actions
     * @param list<string>                    $tags
     * @param list<string>                    $includablePaths
     */
    public function __construct(
        private readonly string $type,
        private readonly string $uriType,
        private readonly bool $hasFields,
        private readonly array $fields = [],
        private readonly array $relations = [],
        private readonly array $operations = [],
        private readonly bool $allowsClientId = false,
        private readonly PaginatorKind $paginatorKind = PaginatorKind::Page,
        private readonly bool $countable = false,
        private readonly array $filters = [],
        private readonly array $sorts = [],
        private readonly array $actions = [],
        private readonly array $tags = [],
        private readonly ?string $description = null,
        private readonly array $includablePaths = [],
        private readonly array $securedOperations = [],
    ) {}

    /**
     * @param list<FieldInterface>            $fields
     * @param list<RelationMetadataInterface> $relations
     * @param list<string>                    $tags
     * @param list<OperationType>|null        $operations        the per-type allow-list; `null` = all five
     * @param list<OperationType>             $securedOperations the subset of operations carrying a security expression
     * @param list<FilterInterface>           $filters
     * @param list<SortInterface>             $sorts
     * @param list<ActionMetadataInterface>   $actions
     * @param list<string>                    $includablePaths
     */
    public static function resource(
        string $type,
        array $fields,
        array $relations = [],
        ?string $uriType = null,
        array $tags = [],
        bool $allowsClientId = false,
        ?string $description = null,
        ?array $operations = null,
        array $securedOperations = [],
        PaginatorKind $paginatorKind = PaginatorKind::Page,
        bool $countable = false,
        array $filters = [],
        array $sorts = [],
        array $actions = [],
        array $includablePaths = [],
    ): self {
        return new self(
            type: $type,
            uriType: $uriType ?? $type,
            hasFields: true,
            fields: $fields,
            relations: $relations,
            operations: $operations ?? [
                OperationType::FetchCollection,
                OperationType::FetchOne,
                OperationType::Create,
                OperationType::Update,
                OperationType::Delete,
            ],
            allowsClientId: $allowsClientId,
            paginatorKind: $paginatorKind,
            countable: $countable,
            filters: $filters,
            sorts: $sorts,
            actions: $actions,
            tags: $tags,
            description: $description,
            includablePaths: $includablePaths,
            securedOperations: $securedOperations,
        );
    }

    /**
     * A serializer-only type with no field inventory (no read/write inventory to
     * project from).
     *
     * @param list<string> $tags
     */
    public static function standalone(string $type, ?string $uriType = null, array $tags = []): self
    {
        return new self(
            type: $type,
            uriType: $uriType ?? $type,
            hasFields: false,
            paginatorKind: PaginatorKind::None,
            tags: $tags,
        );
    }

    public function type(): string
    {
        return $this->type;
    }

    public function uriType(): string
    {
        return $this->uriType;
    }

    public function hasFields(): bool
    {
        return $this->hasFields;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function relations(): array
    {
        return $this->relations;
    }

    public function operations(): array
    {
        return $this->operations;
    }

    public function securedOperations(): array
    {
        return $this->securedOperations;
    }

    public function allowsClientId(): bool
    {
        return $this->allowsClientId;
    }

    public function paginatorKind(): PaginatorKind
    {
        return $this->paginatorKind;
    }

    public function isCountable(): bool
    {
        return $this->countable;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function sorts(): array
    {
        return $this->sorts;
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function includablePaths(): array
    {
        return $this->includablePaths;
    }
}
