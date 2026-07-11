<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The immutable snapshot of a relation's declared configuration, produced by an
 * {@see AbstractRelationBuilder} at {@see AbstractRelationBuilder::build()} time and
 * carried by the readonly {@see AbstractRelationValue} value object — the relation
 * parallel of {@see FieldState}.
 *
 * It keeps the relation builder → value-object handover in one place: the builder
 * accumulates the mutable relationship-shaping state, snapshots it into this DTO,
 * and the value object reads its {@see RelationInterface} accessors straight off the
 * snapshot. Relation types that carry extra state (a {@see BelongsToMany}'s pivot
 * fields) add their own promoted readonly properties alongside it. Internal
 * plumbing — never part of the authoring or consumption surface.
 *
 * @internal
 */
final readonly class RelationState
{
    /**
     * @param list<string>                                                                                              $relatedTypes         the allowed related resource type(s)
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null                              $cannotReplaceWhen    model + request predicate prohibiting replacement
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null                              $cannotRemoveWhen     model + request predicate prohibiting removal
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null                              $cannotAddWhen        model + request predicate prohibiting addition
     * @param \Closure(mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): bool|null                              $cannotBeIncludedWhen model + request predicate prohibiting inclusion
     * @param (\Closure(mixed, mixed, \haddowg\JsonApi\Request\JsonApiRequestInterface): array<string, mixed>)|null     $identifierMetaResolver parent-aware per-identifier meta resolver
     * @param list<\haddowg\JsonApi\Resource\Filter\FilterInterface|\haddowg\JsonApi\Resource\Filter\FilterBuilderInterface> $relationFilters extra filters scoped to the related-collection endpoint
     * @param list<\haddowg\JsonApi\Resource\Sort\SortInterface>                                                        $relationSorts        extra sorts scoped to the related-collection endpoint
     */
    public function __construct(
        public array $relatedTypes = [],
        public ?string $inverseType = null,
        public ?string $uriFieldName = null,
        public bool $includesLinks = true,
        public bool $dataOnlyWhenLoaded = true,
        public bool $allowsReplace = true,
        public bool $allowsRemove = true,
        public bool $exposesRelatedEndpoint = true,
        public bool $exposesRelationshipEndpoint = true,
        public bool $allowsAdd = true,
        public bool $isIncludable = true,
        public ?\Closure $cannotReplaceWhen = null,
        public ?\Closure $cannotRemoveWhen = null,
        public ?\Closure $cannotAddWhen = null,
        public ?\Closure $cannotBeIncludedWhen = null,
        public string|bool|null $securityRead = null,
        public string|bool|null $securityMutate = null,
        public bool $isCountable = false,
        public ?\Closure $identifierMetaResolver = null,
        public ?\haddowg\JsonApi\Pagination\PaginatorInterface $relationPaginator = null,
        public bool $paginationDisabled = false,
        public array $relationFilters = [],
        public array $relationSorts = [],
    ) {}
}
