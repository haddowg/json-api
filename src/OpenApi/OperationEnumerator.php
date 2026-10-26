<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionDeclarations;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Lists the operations a type exposes, in the order the document projects them. The
 * projector, the example projector and anything else that walks a type's operations read
 * them here, so the rules that decide which exist live in one place:
 *
 * - a CRUD operation exists when the type's allow-list names it;
 * - a related read exists when the relation exposes its related endpoint and is not
 *   write-only;
 * - a relationship read exists when the relation exposes its relationship endpoint and
 *   is not write-only;
 * - a relationship mutation exists when the relation exposes its relationship endpoint,
 *   permits that mutation, and the type allows `Update` (add and remove are to-many only);
 * - an action has one operation per distinct method.
 *
 * A later relation or action whose path repeats an earlier one's replaces every operation
 * the earlier one had there, as the document keys a path once.
 *
 * @internal
 */
final class OperationEnumerator
{
    /**
     * @return list<EnumeratedOperation>
     */
    public static function forType(TypeMetadataInterface $type): array
    {
        $uriType = $type->uriType();
        $allowed = [];
        foreach ($type->operations() as $operation) {
            $allowed[$operation->value] = true;
        }

        /** @var array<string, list<EnumeratedOperation>> $byPath */
        $byPath = [];

        foreach ([OperationType::FetchCollection, OperationType::Create] as $operation) {
            if (isset($allowed[$operation->value])) {
                $byPath[PathTemplate::collection($uriType)][] = self::crud($type, $operation, PathTemplate::collection($uriType));
            }
        }
        foreach ([OperationType::FetchOne, OperationType::Update, OperationType::Delete] as $operation) {
            if (isset($allowed[$operation->value])) {
                $byPath[PathTemplate::resource($uriType)][] = self::crud($type, $operation, PathTemplate::resource($uriType));
            }
        }

        $updatable = isset($allowed[OperationType::Update->value]);
        foreach ($type->relations() as $relation) {
            $segment = RelationFacts::uriFieldName($relation);
            $writeOnly = RelationFacts::isWriteOnly($relation);

            if ($relation->exposesRelatedEndpoint() && !$writeOnly) {
                $path = PathTemplate::related($uriType, $segment);
                $byPath[$path] = [self::relation($type, $relation, OperationKind::FetchRelated, $path)];
            }

            if ($relation->exposesRelationshipEndpoint()) {
                $path = PathTemplate::relationship($uriType, $segment);
                $kinds = [];
                if (!$writeOnly) {
                    $kinds[] = OperationKind::FetchRelationship;
                }
                if ($updatable && $relation->allowsReplace()) {
                    $kinds[] = OperationKind::UpdateRelationship;
                }
                if ($updatable && $relation->isToMany() && $relation->allowsAdd()) {
                    $kinds[] = OperationKind::AddRelationship;
                }
                if ($updatable && $relation->isToMany() && $relation->allowsRemove()) {
                    $kinds[] = OperationKind::RemoveRelationship;
                }
                if ($kinds !== []) {
                    $byPath[$path] = \array_map(static fn(OperationKind $kind): EnumeratedOperation => self::relation($type, $relation, $kind, $path), $kinds);
                }
            }
        }

        foreach ($type->actions() as $action) {
            $path = PathTemplate::action($uriType, $action->path(), $action->scope());
            $byPath[$path] = \array_map(
                static fn(string $method): EnumeratedOperation => self::action($type, $action, $method, $path),
                \array_values(\array_unique(\array_map(\strtoupper(...), $action->methods()))),
            );
        }

        return \array_merge(...\array_values($byPath));
    }

    /**
     * Whether the operation's path is a single resource's, and so carries the `{id}` path
     * parameter.
     */
    public static function isResourceScoped(EnumeratedOperation $operation): bool
    {
        return match ($operation->key->kind) {
            OperationKind::FetchCollection, OperationKind::Create, OperationKind::Atomic => false,
            OperationKind::Action => $operation->key->actionScope === ActionScope::Resource,
            default => true,
        };
    }

    private static function crud(TypeMetadataInterface $type, OperationType $operation, string $path): EnumeratedOperation
    {
        $key = OperationKey::crud($operation);

        return new EnumeratedOperation(
            $key,
            $path,
            \strtolower($key->method),
            $key->kind->value . '.' . $type->type(),
            $type->tags(),
        );
    }

    private static function relation(TypeMetadataInterface $type, RelationMetadataInterface $relation, OperationKind $kind, string $path): EnumeratedOperation
    {
        $key = OperationKey::relation($kind, $relation->name());

        return new EnumeratedOperation(
            $key,
            $path,
            \strtolower($key->method),
            $kind->value . '.' . $type->type() . '.' . $relation->name(),
            $type->tags(),
            relation: $relation,
        );
    }

    private static function action(TypeMetadataInterface $type, ActionMetadataInterface $action, string $method, string $path): EnumeratedOperation
    {
        return new EnumeratedOperation(
            OperationKey::forAction($action, $method),
            $path,
            \strtolower($method),
            ActionDeclarations::operationId($type->type(), $action, $method),
            $action->tags(),
            action: $action,
        );
    }
}
