<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Provides the `hydrateForCreate()` method and its supporting hooks.
 *
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
     * @throws JsonApiException
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
     * @throws JsonApiException
     */
    abstract protected function validateRequest(JsonApiRequestInterface $request): void;

    /**
     * Generates a new ID for the resource being created.
     *
     * UUID v4 strings are preferred per the JSON:API specification.
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
     * @throws JsonApiException
     */
    abstract protected function hydrateRelationships(mixed $domainObject, array $data): mixed;

    /**
     * Hydrates `$domainObject` from a POST (create) request.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws DataMemberMissing
     * @throws JsonApiException
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
     * @throws JsonApiException
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
