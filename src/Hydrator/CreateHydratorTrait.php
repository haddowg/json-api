<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Provides the `hydrateForCreate()` method and its supporting hooks.
 *
 * Id sourcing here is **hook-based**, intentionally distinct from the declarative
 * `Id`-field SOURCE/POLICY model on {@see \haddowg\JsonApi\Resource\AbstractResource}
 * (ADR 0048): a subclass owns id sourcing through the abstract {@see generateId()}
 * (mint the server-side id when the client supplies none) and
 * {@see validateClientGeneratedId()} (the client-id acceptance gate). The two create
 * paths are deliberately separate — `AbstractResource` reads its `Id` field's
 * `allowClientId()`/`generated()`/store-provided policy, while a hand-written hydrator
 * built on this family expresses the same choices by implementing these hooks (e.g. a
 * UUID id mints one in `generateId()`, a client-id-required hydrator throws from
 * `validateClientGeneratedId()` on an empty id). `generateId()` being abstract, this
 * family never auto-mints silently: a subclass must implement it. The
 * `CreateHydratorTraitTest` pins that contract so the two paths cannot drift unnoticed.
 */
trait CreateHydratorTrait
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    abstract protected function validateType(array $data): void;

    /**
     * Validates a client-generated ID.
     *
     * Throw {@see ClientGeneratedIdNotSupported} when the hydrator does not
     * accept client-supplied IDs, or the appropriate exception when the given
     * ID is otherwise invalid.
     *
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    abstract protected function validateClientGeneratedId(
        string $clientGeneratedId,
        JsonApiRequestInterface $request,
    ): void;

    /**
     * Validates the incoming request.
     *
     * Called after type and ID validation. Override to add request-level
     * constraints; the default implementation is a no-op.
     *
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    abstract protected function validateRequest(JsonApiRequestInterface $request): void;

    /**
     * Generates a new ID for the resource being created, when the client supplies
     * none.
     *
     * UUID v4 strings are preferred per the JSON:API specification. This is the
     * hook-based equivalent of {@see \haddowg\JsonApi\Resource\Field\Id::generated()}
     * on the declarative `AbstractResource` path (ADR 0048): a subclass must implement
     * it (it is abstract — there is no silent auto-UUID), minting whatever format it
     * needs. A store-provided id (the DB assigns it) is expressed by having
     * {@see setId()} leave the id untouched, not here.
     */
    abstract protected function generateId(): string;

    /**
     * Applies `$id` to `$domainObject`.
     *
     * Mutate `$domainObject` by reference **or** return the updated object;
     * a non-null return value replaces the current domain object.
     *
     * @param mixed $domainObject
     * @return mixed
     */
    abstract protected function setId(mixed $domainObject, string $id): mixed;

    /**
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     */
    abstract protected function hydrateAttributes(mixed $domainObject, array $data): mixed;

    /**
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    abstract protected function hydrateRelationships(mixed $domainObject, array $data): mixed;

    /**
     * Hydrates `$domainObject` from a POST (create) request.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws DataMemberMissing
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    public function hydrateForCreate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $data = $request->getResource();
        if ($data === null) {
            throw new DataMemberMissing();
        }

        /** @var array<string, mixed> $data */
        $this->validateType($data);
        $domainObject = $this->hydrateIdForCreate($domainObject, $data, $request);
        $this->validateRequest($request);
        $domainObject = $this->hydrateAttributes($domainObject, $data);
        $domainObject = $this->hydrateRelationships($domainObject, $data);

        return $domainObject;
    }

    /**
     * Resolves and applies the resource ID for a create operation.
     *
     * Uses the client-supplied ID when present; otherwise generates one via
     * {@see generateId()}.
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws ResourceIdInvalid
     * @throws \haddowg\JsonApi\Exception\JsonApiExceptionInterface
     */
    protected function hydrateIdForCreate(
        mixed $domainObject,
        array $data,
        JsonApiRequestInterface $request,
    ): mixed {
        $id = '';
        if (empty($data['id']) === false) {
            if (\is_string($data['id']) === false) {
                throw new ResourceIdInvalid(\gettype($data['id']));
            }
            $id = $data['id'];
        }

        $this->validateClientGeneratedId($id, $request);

        if ($id === '') {
            $id = $this->generateId();
        }

        $result = $this->setId($domainObject, $id);
        if ($result !== null) {
            $domainObject = $result;
        }

        return $domainObject;
    }
}
