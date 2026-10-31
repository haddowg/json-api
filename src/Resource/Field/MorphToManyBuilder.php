<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphToMany} to-many
 * relationship: the collection's members may each be one of several declared types,
 * passed as the mandatory list to {@see make()}. {@see build()} freezes it into a
 * readonly {@see MorphToMany} value object.
 */
final class MorphToManyBuilder extends AbstractRelationBuilder
{
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
     * It changes reads only; `fillUsing()` and the default write are unchanged. It
     * cannot be combined with {@see identifierMeta()}, whose resolver needs the member.
     *
     * @param \Closure(mixed, JsonApiRequestInterface): iterable<array{0: string, 1: string|int}> $identifiers
     *
     * @return static
     */
    public function identifiedBy(\Closure $identifiers): static
    {
        $this->identifiedBy = $identifiers;

        return $this;
    }

    public function build(): MorphToMany
    {
        if ($this->identifiedBy !== null && $this->identifierMetaResolver !== null) {
            throw new \LogicException(\sprintf(
                'MorphToMany relation "%s" cannot combine identifiedBy() with identifierMeta(): its linkage renders without the members the identifier meta resolver receives.',
                $this->name,
            ));
        }

        return new MorphToMany($this->fieldState(), $this->relationState(), $this->identifiedBy);
    }
}
