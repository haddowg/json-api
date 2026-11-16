<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;

/**
 * A singleton type declared with an operation or action it cannot serve: a collection fetch,
 * a create, a delete or a collection-scope action. A wiring error, raised when an adapter
 * registers the type and when the OpenAPI projector reads its metadata, never an error
 * document.
 */
final class SingletonMisdeclared extends \LogicException
{
    /**
     * Refuses a singleton type whose operation allow-list names a collection fetch, a create
     * or a delete, or that mounts a collection-scope action. An adapter calls it when it
     * registers a singleton type.
     *
     * @param list<OperationType> $operations        the type's operation allow-list
     * @param list<string>        $collectionActions the paths of the type's collection-scope actions
     *
     * @throws self
     */
    public static function assertExclusive(string $type, array $operations, array $collectionActions = []): void
    {
        $refused = [];
        foreach ($operations as $operation) {
            if (\in_array($operation, [OperationType::FetchCollection, OperationType::Create, OperationType::Delete], true)) {
                $refused[$operation->value] = $operation->value;
            }
        }

        if ($refused !== []) {
            throw new self(\sprintf(
                'Type "%s" is a singleton, so it serves only fetch and update, but it declares %s. Declare a second type over the same entity for those operations; an entity can back several types.',
                $type,
                \implode(', ', $refused),
            ));
        }

        if ($collectionActions !== []) {
            throw new self(\sprintf(
                'Type "%s" is a singleton, so every action on it is resource-scoped, but it declares the collection-scope action "%s". Mount it on the resource, or declare a second type over the same entity for collection actions.',
                $type,
                $collectionActions[0],
            ));
        }
    }
}
