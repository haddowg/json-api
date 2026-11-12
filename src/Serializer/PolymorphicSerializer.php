<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Schema\Link\ResourceLinks;

/**
 * A {@see SerializerInterface} decorator that renders a heterogeneous (mixed-type)
 * collection by resolving each object's real serializer — typically via
 * {@see \haddowg\JsonApi\Resource\Field\RelationInterface::resolveSerializer()} —
 * and delegating every method to it. Binding one of these to a relationship gives
 * each member its own correct `type` / `id` / attributes, so a polymorphic to-many
 * relationship renders per-member without any change to the transformer,
 * {@see \haddowg\JsonApi\Schema\Relationship\ToManyRelationship}, or a host's
 * related-resource collection.
 *
 * The member serializers, when given, are the serializers of every type a member may
 * have. They make a relation countable on the mixed response when any member type
 * counts it, so `?withCount` validates against their union and each member renders
 * its own `meta.total`. Without them nothing is countable.
 */
final class PolymorphicSerializer implements SerializerInterface, IncludeControlsInterface, RendersDeclaredLinksInterface, CountableControlsInterface
{
    /**
     * @var list<SerializerInterface>
     */
    private readonly array $members;

    /**
     * @param \Closure(mixed): SerializerInterface $serializerFor resolves the serializer for a given member object
     * @param iterable<SerializerInterface>        $members       the serializer of each type a member may have
     */
    public function __construct(private readonly \Closure $serializerFor, iterable $members = [])
    {
        $list = [];
        foreach ($members as $member) {
            $list[] = $member;
        }
        $this->members = $list;
    }

    /**
     * A serializer for the members of a polymorphic relation: each member resolves
     * through {@see RelationInterface::resolveSerializer()}, and every registered
     * related type is a member type.
     */
    public static function forRelation(RelationInterface $relation, SerializerResolverInterface $resolver): self
    {
        $members = [];
        foreach ($relation->relatedTypes() as $type) {
            if ($resolver->hasSerializerFor($type)) {
                $members[] = $resolver->serializerFor($type);
            }
        }

        return new self(
            static fn(mixed $object): SerializerInterface => $relation->resolveSerializer($object, $resolver)
                ?? throw new \LogicException(\sprintf('No declared type of the "%s" relationship serializes the related object.', $relation->name())),
            $members,
        );
    }

    /**
     * The serializer of each type a member may have, as given at construction.
     *
     * @return list<SerializerInterface>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * The union of the member types' countable relationships: a name is countable
     * here when at least one member type counts it.
     */
    public function getCountableRelationships(mixed $object): array
    {
        $names = [];
        foreach ($this->members as $member) {
            if ($member instanceof CountableControlsInterface) {
                \array_push($names, ...$member->getCountableRelationships($object));
            }
        }

        return \array_values(\array_unique($names));
    }

    public function getType(mixed $object): string
    {
        return $this->for($object)->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->for($object)->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getMeta($object, $request);
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->for($object)->getLinks($object, $request);
    }

    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array
    {
        $serializer = $this->for($object);

        return $serializer instanceof RendersDeclaredLinksInterface ? $serializer->renderDeclaredLinks($object, $request) : [];
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->for($object)->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->for($object)->getRelationships($object, $request);
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        $serializer = $this->for($object);

        return $serializer instanceof IncludeControlsInterface
            ? $serializer->getNonIncludableRelationships($request, $object)
            : [];
    }

    /**
     * The root-scoped depth override and include path lists resolve
     * against the request's PRIMARY/root serializer, never a relationship-member
     * one — a polymorphic serializer only ever serializes related members, where
     * there is no single inner serializer to delegate to (members span types). It
     * is therefore unrestricted on all three: a member's own resource still enforces
     * its per-relation includability during the recursion.
     */
    public function maxIncludeDepth(): ?int
    {
        return null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return null;
    }

    public function getExcludedIncludePaths(): array
    {
        return [];
    }

    private function for(mixed $object): SerializerInterface
    {
        return ($this->serializerFor)($object);
    }
}
