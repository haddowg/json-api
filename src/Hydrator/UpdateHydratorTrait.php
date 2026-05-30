<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Hydrator;

use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\JsonApiException;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdMissing;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Provides `hydrateForUpdate()` and `hydrateForRelationshipUpdate()` along with
 * their supporting hooks.
 *
 * @see https://github.com/woohoolabs/yin — original work (MIT), from which this derives.
 */
trait UpdateHydratorTrait
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws ResourceTypeMissing
     * @throws ResourceTypeUnacceptable
     */
    abstract protected function validateType(array $data): void;

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
     * Returns the relationship hydrators keyed by relationship name.
     *
     * @param mixed $domainObject
     * @return array<string, callable>
     */
    abstract protected function getRelationshipHydrator(mixed $domainObject): array;

    /**
     * @param mixed $domainObject
     * @param array<string, mixed>|null $relationshipData
     * @param array<string, mixed>|null $data
     * @return mixed
     *
     * @throws JsonApiException
     */
    abstract protected function doHydrateRelationship(
        mixed $domainObject,
        string $relationshipName,
        callable $hydrator,
        ?array $relationshipData,
        ?array $data,
    ): mixed;

    /**
     * Hydrates `$domainObject` from a PATCH (update) request.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws DataMemberMissing
     * @throws JsonApiException
     */
    public function hydrateForUpdate(JsonApiRequestInterface $request, mixed $domainObject): mixed
    {
        $data = $request->getResource();
        if ($data === null) {
            throw new DataMemberMissing();
        }

        /** @var array<string, mixed> $data */
        $this->validateType($data);
        $domainObject = $this->hydrateIdForUpdate($domainObject, $data);
        $this->validateRequest($request);
        $domainObject = $this->hydrateAttributes($domainObject, $data);
        $domainObject = $this->hydrateRelationships($domainObject, $data);

        return $domainObject;
    }

    /**
     * Hydrates a single relationship from a PATCH relationship endpoint.
     *
     * @param mixed $domainObject
     * @return mixed
     *
     * @throws RelationshipNotExists
     * @throws JsonApiException
     */
    public function hydrateForRelationshipUpdate(
        string $relationship,
        JsonApiRequestInterface $request,
        mixed $domainObject,
    ): mixed {
        $relationshipHydrators = $this->getRelationshipHydrator($domainObject);

        if (isset($relationshipHydrators[$relationship]) === false) {
            throw new RelationshipNotExists($relationship);
        }

        $relationshipHydrator = $relationshipHydrators[$relationship];

        $body = $request->getParsedBody();
        $resource = $request->getResource();

        /** @var array<string, mixed>|null $relationshipData */
        $relationshipData = \is_array($body) ? $body : null;
        /** @var array<string, mixed>|null $data */
        $data = \is_array($resource) ? $resource : null;

        return $this->doHydrateRelationship(
            $domainObject,
            $relationship,
            $relationshipHydrator,
            $relationshipData,
            $data,
        );
    }

    /**
     * Validates and applies the resource ID for an update operation.
     *
     * @param mixed $domainObject
     * @param array<string, mixed> $data
     * @return mixed
     *
     * @throws ResourceIdMissing
     * @throws ResourceIdInvalid
     */
    protected function hydrateIdForUpdate(mixed $domainObject, array $data): mixed
    {
        if (empty($data['id'])) {
            throw new ResourceIdMissing();
        }

        if (\is_string($data['id']) === false) {
            throw new ResourceIdInvalid(\gettype($data['id']));
        }

        $result = $this->setId($domainObject, $data['id']);
        if ($result !== null) {
            $domainObject = $result;
        }

        return $domainObject;
    }
}
