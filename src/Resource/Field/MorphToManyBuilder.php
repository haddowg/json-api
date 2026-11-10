<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Collection\LinkRows\LinkRowQuery;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Filter\FilterBuilderInterface;
use haddowg\JsonApi\Resource\Filter\TargetsColumn;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortMatcher;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphToMany} to-many
 * relationship: the collection's members may each be one of several declared types,
 * passed as the mandatory list to {@see make()}. Declared with {@see identifiedByRows()}
 * or {@see identifiedBy()}, it may also declare pivot fields on the link rows
 * ({@see fields()}).
 * {@see build()} freezes it into a readonly {@see MorphToMany} value object.
 */
final class MorphToManyBuilder extends AbstractRelationBuilder
{
    use DeclaresPivotFields;
    use DeclaresPolymorphicTypes;

    /**
     * @var (\Closure(mixed, JsonApiRequestInterface): mixed)|null
     */
    private ?\Closure $identifiedBy = null;

    /**
     * @var array{owner: string, type: string, id: string, typeMap: array<array-key, string>}|null
     */
    private ?array $identifiedByRows = null;

    /**
     * Reads the members off the parent's link rows, each row naming one member by a stored
     * type and id, and declares those rows in full so a data layer can query them itself.
     * Name the class backing the rows with {@see through()}, and here the row's fields:
     * `$owner` points at the owning parent, `$type` holds the member's stored type and
     * `$id` its storage key.
     *
     *     MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
     *         ->storedAs('userCompanies')
     *         ->through(UserCompany::class)
     *         ->identifiedByRows(owner: 'user', type: 'companyType', id: 'companyId')
     *
     * Each name is a field of the row as its model sees it: a Doctrine entity's property,
     * an Eloquent model's attribute or relation. Core reads the rows in memory as the
     * relation's value on the parent, its `storedAs()` field or its name.
     *
     * The stored type is the member's JSON:API type unless `$typeMap` maps stored values
     * to JSON:API types, as an Eloquent morph map does. A declared map is complete: a row
     * whose stored type it doesn't list is not a member, and neither is a row whose type
     * names no declared type. Rows are ordered by the request's sort, else the relation's
     * default order, with ties broken by the stored type then the id.
     *
     * The data layer writes the rows too, from the
     * {@see \haddowg\JsonApi\Collection\LinkRows\LinkRowWritePlan} core computes, so the
     * relation takes no `fillUsing()`, and a declared map must hold one stored value per
     * declared type.
     *
     * This replaces {@see identifiedBy()}, which stays for storage these fields can't
     * describe and whose rows only core's in-memory query can filter, sort and page.
     *
     * @param array<array-key, string> $typeMap stored type => JSON:API type
     *
     * @return static
     */
    public function identifiedByRows(string $owner, string $type, string $id, array $typeMap = []): static
    {
        $this->identifiedByRows = ['owner' => $owner, 'type' => $type, 'id' => $id, 'typeMap' => $typeMap];

        return $this;
    }

    /**
     * Reads the members' identifiers off the parent instead of the members themselves.
     * The closure receives the parent model and the request and returns a list of
     * `[$type, $id]` pairs, where `$type` is one of the declared JSON:API types and
     * `$id` the member's storage key:
     *
     *     MorphToMany::make('companies', ['agencies', 'advertisers', 'publishers'])
     *         ->identifiedBy(static fn(mixed $user): array => $user instanceof User
     *             ? array_map(static fn(UserCompany $row): array => [$row->companyType, $row->companyId], $user->companies)
     *             : []);
     *
     * The linkage renders from the pairs in order, without loading a member, and each
     * id goes through its type's id encoder when it has one. A pair repeated in the
     * list renders once. A member is read only to render it into `included`, with the
     * serializer of its pair's type: from the host's
     * {@see \haddowg\JsonApi\Serializer\RelationshipLinkageInterface} when it supplies
     * the members, otherwise the relation's usual read. A type the relation does not
     * declare is a `\LogicException`.
     *
     * Prefer {@see identifiedByRows()} when the pairs live on rows a data layer can query:
     * a closure's rows are filtered, sorted and paged in memory, every row loaded first.
     *
     * It changes reads only; `fillUsing()` and the default write are unchanged. An
     * {@see identifierMeta()} resolver receives each member as an
     * {@see \haddowg\JsonApi\Schema\Relationship\IdentifiedMember}, since the linkage
     * renders without loading it: its pair, its row and, when the host supplied it, its
     * target.
     *
     * When the relation declares pivot fields ({@see fields()}), return a
     * `[$type, $id, $row]` triple per member instead, `$row` being the link row the pivot
     * fields are read from:
     *
     *     ->identifiedBy(static fn(User $user): array => array_map(
     *         static fn(UserCompany $row): array => [$row->companyType, $row->companyId, $row],
     *         $user->companies,
     *     ))
     *     ->fields(Integer::make('position'), DateTime::make('linkedAt')->readOnly())
     *
     * @param \Closure(mixed, JsonApiRequestInterface): iterable<array{0: string, 1: string|int, 2?: mixed}> $identifiers
     *
     * @return static
     */
    public function identifiedBy(\Closure $identifiers): static
    {
        $this->identifiedBy = $identifiers;

        return $this;
    }

    protected function buildsToMany(): bool
    {
        return true;
    }

    /**
     * Positions the members by the stored `Integer` pivot field `$field` of the link
     * rows, as {@see BelongsToManyBuilder::positionedBy()} does. A field that isn't a
     * declared, stored `Integer` pivot field fails the build.
     *
     * @return static
     */
    public function positionedBy(string $field): static
    {
        return $this->positionMembersBy($field);
    }

    public function build(): MorphToMany
    {
        if ($this->pivotFields !== [] && $this->identifiedBy === null && $this->identifiedByRows === null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares pivot fields, so it must be declared with identifiedBy() or identifiedByRows(): its link rows map each member to its type and id.',
                $this->name,
            ));
        }

        $rows = $this->linkRowFields();
        $this->guardDefaultSort();
        $pivotFields = $this->positionedPivotFields();
        if ($rows !== null) {
            $this->guardRowFields($rows);
        }

        return new MorphToMany($this->fieldState(), $this->relationState(), $this->identifiedBy, $pivotFields, $this->pivotThrough, $rows);
    }

    /**
     * The declared link-row fields, checked against what the builder can see.
     */
    private function linkRowFields(): ?LinkRowFields
    {
        if ($this->identifiedByRows === null) {
            return null;
        }

        if ($this->identifiedBy !== null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares both identifiedBy() and identifiedByRows(). Declare one: identifiedByRows() when its link rows have an owner, type and id field, identifiedBy() for anything else.',
                $this->name,
            ));
        }

        ['owner' => $owner, 'type' => $type, 'id' => $id, 'typeMap' => $typeMap] = $this->identifiedByRows;
        foreach (['owner' => $owner, 'type' => $type, 'id' => $id] as $role => $field) {
            if ($field === '' || \str_contains($field, '.')) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" declares identifiedByRows() with %s "%s", which is not a field name. Name a field of the link row itself.',
                    $this->name,
                    $role === 'owner' ? 'an owner' : 'a ' . $role,
                    $field,
                ));
            }
        }

        if (\count(\array_unique([$owner, $type, $id])) !== 3) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares identifiedByRows() with the same field twice; the owner, type and id are three fields of the link row.',
                $this->name,
            ));
        }

        if ($this->pivotThrough === null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares identifiedByRows() but no through(); name the class backing its link rows, so a data layer can query them.',
                $this->name,
            ));
        }

        foreach ($typeMap as $stored => $mapped) {
            if ((string) $stored === '' || !\in_array($mapped, $this->relatedTypes, true)) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" maps the stored type "%s" to "%s", which it does not declare. Declared types: %s.',
                    $this->name,
                    (string) $stored,
                    $mapped,
                    \implode(', ', $this->relatedTypes),
                ));
            }
        }

        if ($typeMap !== []) {
            foreach ($this->relatedTypes as $declared) {
                $stored = \array_keys($typeMap, $declared, true);
                if (\count($stored) !== 1) {
                    throw new \LogicException(\sprintf(
                        'MorphToMany relation "%s" maps %s to the type "%s". A typeMap maps one stored value to each declared type, so a new link row has one value to write.',
                        $this->name,
                        $stored === [] ? 'no stored value' : 'the stored values "' . \implode('", "', \array_map(\strval(...), $stored)) . '"',
                        $declared,
                    ));
                }
            }
        }

        if ($this->fillUsing !== null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares both identifiedByRows() and fillUsing(). The data layer writes the link rows of a relation declared with identifiedByRows(), from a plan core computes; declare identifiedBy() instead to write them with fillUsing().',
                $this->name,
            ));
        }

        return new LinkRowFields($this->pivotThrough, $owner, $type, $id, $this->relatedTypes, $typeMap);
    }

    /**
     * A data layer queries the link rows directly, so every stored pivot field and every
     * pivot filter must name a field of the row itself, and the position must not
     * overwrite the fields that link it.
     */
    private function guardRowFields(LinkRowFields $rows): void
    {
        foreach ($this->pivotFields as $field) {
            $column = $field->column();
            if ($column !== null && ($column === '' || \str_contains($column, '.'))) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" declares the pivot field "%s" stored as "%s", which is not a field of its link row. A relation declared with identifiedByRows() stores each pivot field on the row.',
                    $this->name,
                    $field->name(),
                    $column,
                ));
            }
        }

        foreach ($this->relationFilters as $filter) {
            $built = $filter instanceof FilterBuilderInterface ? $filter->build() : $filter;
            if (!$built instanceof TargetsColumn || !\str_starts_with($built->targetColumn(), LinkRowQuery::PIVOT_PREFIX)) {
                continue;
            }

            $column = LinkRowQuery::rowColumn($built->targetColumn());
            if ($column === '' || \str_contains($column, '.')) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" declares the filter "%s" on "%s", which is not a field of its link row. A pivot filter compares one field of the row.',
                    $this->name,
                    $built->key(),
                    $built->targetColumn(),
                ));
            }
        }

        $position = $this->positionField !== null ? ($this->pivotFields[$this->positionField] ?? null)?->column() : null;
        if ($position !== null && \in_array($position, [$rows->owner, $rows->type, $rows->id], true)) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" is positioned by "%s", which is the field that links its rows. A position is a field of its own on the link row.',
                $this->name,
                $this->positionField,
            ));
        }
    }

    /**
     * A polymorphic relation sorts only over the link rows of an identified relation, by
     * the sorts its pivot fields derive, all of which this builder can see.
     */
    private function guardDefaultSort(): void
    {
        if ($this->defaultSort === []) {
            return;
        }

        if ($this->identifiedBy === null && $this->identifiedByRows === null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares defaultSort(), but a polymorphic relation not declared with identifiedBy() takes no sort. Declare identifiedByRows() or identifiedBy() to sort its link rows by their pivot fields.',
                $this->name,
            ));
        }

        $sorts = RelationSorts::pivotSortsOf(\array_values($this->pivotFields));
        foreach ($this->defaultSort as $directive) {
            if (!SortMatcher::recognises($sorts, $directive->sort->key())) {
                throw new \LogicException(\sprintf(
                    'MorphToMany relation "%s" declares a defaultSort() on "%s", which is not one of its pivot fields. It sorts only by its stored pivot fields%s.',
                    $this->name,
                    $directive->sort->key(),
                    $sorts === [] ? ', and declares none' : ': ' . \implode(', ', \array_map(static fn(\haddowg\JsonApi\Resource\Sort\SortInterface $sort): string => $sort->key(), $sorts)),
                ));
            }
        }
    }
}
