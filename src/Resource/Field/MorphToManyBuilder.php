<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Sort\RelationSorts;
use haddowg\JsonApi\Resource\Sort\SortMatcher;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphToMany} to-many
 * relationship: the collection's members may each be one of several declared types,
 * passed as the mandatory list to {@see make()}. Declared with {@see identifiedBy()},
 * it may also declare pivot fields on the link rows ({@see fields()}).
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
        if ($this->pivotFields !== [] && $this->identifiedBy === null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares pivot fields, so it must be declared with identifiedBy(): its link rows map each member to its type and id.',
                $this->name,
            ));
        }

        $this->guardDefaultSort();

        return new MorphToMany($this->fieldState(), $this->relationState(), $this->identifiedBy, $this->positionedPivotFields(), $this->pivotThrough);
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

        if ($this->identifiedBy === null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" declares defaultSort(), but a polymorphic relation not declared with identifiedBy() takes no sort.',
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
