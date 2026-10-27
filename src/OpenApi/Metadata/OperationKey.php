<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Atomic\AtomicOperationCode;

/**
 * Names one operation of a type independently of how it is routed: its kind, the
 * relation or action it applies to, its HTTP method and its declared safety.
 *
 * A CRUD, related or relationship operation has a {@see name()} in the grammar a type's
 * {@see DeclaresOperationExamples::operationExamples()} is keyed by: `fetchCollection`,
 * `fetchOne`, `create`, `update`, `delete`, or `fetchRelated`, `fetchRelationship`,
 * `updateRelationship`, `addRelationship` or `removeRelationship` followed by a dot and
 * the relation name. {@see fromName()} parses that grammar and nothing else.
 *
 * An action's name is `action.<path>.<method>` in lower case, which is unique within a
 * type because {@see ActionDeclarations} refuses one path at both scopes. The Atomic
 * Operations endpoint is `atomic`. Neither parses back, since a name cannot carry an
 * action's scope or safety.
 *
 * A key can also name the type the operation acts on ({@see withType()}). The name leaves
 * it out, since the type is usually known from context.
 */
final readonly class OperationKey
{
    private function __construct(
        public OperationKind $kind,
        public string $method,
        public ?string $relation = null,
        public ?string $actionPath = null,
        public ?ActionScope $actionScope = null,
        public ?OperationSafety $safety = null,
        public ?string $type = null,
    ) {}

    /**
     * The same operation on the named JSON:API type. The key's {@see name()} does not
     * change; the type is there for a reader that is not handed the type's metadata, such
     * as a scope resolver asked about a type the server does not describe.
     */
    public function withType(?string $type): self
    {
        return new self($this->kind, $this->method, $this->relation, $this->actionPath, $this->actionScope, $this->safety, $type);
    }

    /**
     * One of the five CRUD operations.
     *
     * @throws \InvalidArgumentException when `$kind` is not a CRUD kind
     */
    public static function crud(OperationKind|OperationType $kind): self
    {
        if ($kind instanceof OperationType) {
            $kind = OperationKind::fromOperationType($kind);
        }
        if ($kind->operationType() === null) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a CRUD operation.', $kind->value));
        }

        return new self($kind, (string) $kind->method());
    }

    /**
     * One of the related or relationship operations of the named relation.
     *
     * @throws \InvalidArgumentException when `$kind` does not act on a relation, or the name is empty
     */
    public static function relation(OperationKind $kind, string $relation): self
    {
        if (!$kind->takesRelation()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a related or relationship operation.', $kind->value));
        }
        if ($relation === '') {
            throw new \InvalidArgumentException(\sprintf('A "%s" operation needs a relation name.', $kind->value));
        }

        return new self($kind, (string) $kind->method(), relation: $relation);
    }

    /**
     * One method of a custom action. `$method` is upper-cased.
     */
    public static function action(string $path, ActionScope $scope, string $method, ?OperationSafety $safety = null): self
    {
        return new self(OperationKind::Action, \strtoupper($method), actionPath: $path, actionScope: $scope, safety: $safety);
    }

    /**
     * One method of the action, carrying the safety it declares.
     */
    public static function forAction(ActionMetadataInterface $action, string $method): self
    {
        return self::action($action->path(), $action->scope(), $method, OperationSafety::of($action));
    }

    /**
     * The Atomic Operations endpoint.
     */
    public static function atomic(): self
    {
        return new self(OperationKind::Atomic, 'POST');
    }

    /**
     * The operation one entry of an Atomic Operations batch performs: `add`, `update` and
     * `remove` are a create, update and delete, or with a `ref.relationship` an add to,
     * replacement of and removal from that relationship.
     */
    public static function forAtomicOperation(AtomicOperationCode $code, ?string $relationship = null): self
    {
        if ($relationship !== null && $relationship !== '') {
            return self::relation(match ($code) {
                AtomicOperationCode::Add => OperationKind::AddRelationship,
                AtomicOperationCode::Update => OperationKind::UpdateRelationship,
                AtomicOperationCode::Remove => OperationKind::RemoveRelationship,
            }, $relationship);
        }

        return self::crud(match ($code) {
            AtomicOperationCode::Add => OperationKind::Create,
            AtomicOperationCode::Update => OperationKind::Update,
            AtomicOperationCode::Remove => OperationKind::Delete,
        });
    }

    /**
     * Parses a CRUD, related or relationship operation name.
     *
     * @throws \InvalidArgumentException when `$name` is not one
     */
    public static function fromName(string $name): self
    {
        return self::tryFromName($name) ?? throw new \InvalidArgumentException(\sprintf(
            '"%s" is not an operation name. Use one of %s, or one of %s followed by a dot and the relation name.',
            $name,
            \implode(', ', \array_map(static fn(OperationKind $kind): string => $kind->value, self::crudKinds())),
            \implode(', ', \array_map(static fn(OperationKind $kind): string => $kind->value, self::relationKinds())),
        ));
    }

    /**
     * {@see fromName()}, or `null` when `$name` is not an operation name.
     */
    public static function tryFromName(string $name): ?self
    {
        $dot = \strpos($name, '.');
        if ($dot === false) {
            $kind = OperationKind::tryFrom($name);

            return $kind !== null && $kind->operationType() !== null ? self::crud($kind) : null;
        }

        $kind = OperationKind::tryFrom(\substr($name, 0, $dot));
        $relation = \substr($name, $dot + 1);
        if ($kind === null || !$kind->takesRelation() || $relation === '') {
            return null;
        }

        return self::relation($kind, $relation);
    }

    /**
     * The operation's name: the `operationExamples` key for a CRUD, related or
     * relationship operation, `action.<path>.<method>` for an action, `atomic` for the
     * Atomic Operations endpoint.
     */
    public function name(): string
    {
        return match (true) {
            $this->relation !== null => $this->kind->value . '.' . $this->relation,
            $this->kind === OperationKind::Action => 'action.' . $this->actionPath . '.' . \strtolower($this->method),
            default => $this->kind->value,
        };
    }

    /**
     * @return list<OperationKind>
     */
    private static function crudKinds(): array
    {
        return \array_values(\array_filter(OperationKind::cases(), static fn(OperationKind $kind): bool => $kind->operationType() !== null));
    }

    /**
     * @return list<OperationKind>
     */
    private static function relationKinds(): array
    {
        return \array_values(\array_filter(OperationKind::cases(), static fn(OperationKind $kind): bool => $kind->takesRelation()));
    }
}
