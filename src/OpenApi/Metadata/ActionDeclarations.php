<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\VendorExtensions;

/**
 * Validation for a type's custom-action declarations, and the operation ids and safety
 * flags the projector derives from them. Core owns it so every integration rejects the
 * same declarations: the projector runs {@see validateType()} on every type it projects,
 * and an integration should call {@see validate()} when it registers a type's actions, so
 * a bad declaration fails at boot rather than when the document is first generated.
 *
 * An action's operation id is `action.<type>.<path>`, with `.<method>` appended in lower
 * case when the action answers to more than one method. The rules keep those ids unique
 * even for a consumer that flattens them, by case and by punctuation, into a name such as
 * a tool name:
 *
 * - one type may not declare the same action path at both scopes, since both would get
 *   the same id;
 * - an action path may not read as one of the operation-id words in {@see RESERVED_NAMES}
 *   or as one of the type's relation names;
 * - no two of the type's action ids may flatten to the same name;
 * - an action may not be declared both read-only and destructive;
 * - the action's own vendor extension names must be valid.
 */
final class ActionDeclarations
{
    /**
     * The words the projector builds the type's other operation ids from: its CRUD
     * operations and its related and relationship endpoints.
     */
    public const RESERVED_NAMES = [
        'fetchCollection',
        'fetchOne',
        'create',
        'update',
        'delete',
        'fetchRelated',
        'fetchRelationship',
        'updateRelationship',
        'addRelationship',
        'removeRelationship',
    ];

    /**
     * Checks one type's action declarations.
     *
     * @param iterable<ActionMetadataInterface> $actions
     * @param iterable<string>                  $relationNames the type's relation names
     *
     * @throws \LogicException naming the type and the action at fault
     */
    public static function validate(string $type, iterable $actions, iterable $relationNames = []): void
    {
        $reserved = [];
        foreach (self::RESERVED_NAMES as $name) {
            $reserved[self::flatten($name)] = 'the "' . $name . '" operation';
        }
        foreach ($relationNames as $name) {
            $reserved[self::flatten($name)] ??= 'the "' . $name . '" relation';
        }

        $scopes = [];
        $ids = [];
        foreach ($actions as $action) {
            $path = $action->path();

            $clash = $reserved[self::flatten($path)] ?? null;
            if ($clash !== null) {
                throw new \LogicException(\sprintf(
                    'The "%s" action on type "%s" is named like %s, so a consumer that flattens operation ids could not tell them apart. Give the action a different name.',
                    $path,
                    $type,
                    $clash,
                ));
            }

            $other = $scopes[$path] ?? null;
            if ($other !== null && $other !== $action->scope()) {
                throw new \LogicException(\sprintf(
                    'Type "%s" declares the "%s" action at both the resource and the collection scope, and both would get the operation id "action.%s.%s". Give one of them a different name.',
                    $type,
                    $path,
                    $type,
                    $path,
                ));
            }
            $scopes[$path] = $action->scope();

            foreach (self::methods($action) as $method) {
                $id = self::operationId($type, $action, $method);
                $flat = self::flatten($id);
                if (isset($ids[$flat])) {
                    throw new \LogicException(\sprintf(
                        'Type "%s" declares actions whose operation ids "%s" and "%s" differ only in case or punctuation. Give the "%s" action a different name.',
                        $type,
                        $ids[$flat],
                        $id,
                        $path,
                    ));
                }
                $ids[$flat] = $id;
            }

            if ($action instanceof DeclaresActionSafety && $action->readOnly() === true && $action->destructive() === true) {
                throw new \LogicException(\sprintf(
                    'The "%s" action on type "%s" is declared both read-only and destructive. An action that changes nothing cannot destroy anything; drop one of the two.',
                    $path,
                    $type,
                ));
            }

            if ($action instanceof DeclaresActionExtensions) {
                VendorExtensions::normalise($action->extensions());
            }
        }
    }

    /**
     * {@see validate()} for a type's metadata, reading its actions and relation names.
     *
     * @throws \LogicException
     */
    public static function validateType(TypeMetadataInterface $type): void
    {
        $relationNames = [];
        foreach ($type->relations() as $relation) {
            $relationNames[] = $relation->name();
        }

        self::validate($type->type(), $type->actions(), $relationNames);
    }

    /**
     * The operation id of one of the action's methods: `action.<type>.<path>`, with the
     * lower-case method appended when the action answers to more than one.
     */
    public static function operationId(string $type, ActionMetadataInterface $action, string $method): string
    {
        $id = 'action.' . $type . '.' . $action->path();

        return \count(self::methods($action)) > 1 ? $id . '.' . \strtolower($method) : $id;
    }

    /**
     * The safety flags the action declares, keyed `readOnly`, `destructive` and
     * `idempotent`, leaving out every flag it does not declare.
     *
     * @return array<string, bool>
     */
    public static function safety(ActionMetadataInterface $action): array
    {
        if (!$action instanceof DeclaresActionSafety) {
            return [];
        }

        return \array_filter(
            ['readOnly' => $action->readOnly(), 'destructive' => $action->destructive(), 'idempotent' => $action->idempotent()],
            static fn(?bool $flag): bool => $flag !== null,
        );
    }

    /**
     * The action's distinct methods, in lower case.
     *
     * @return list<string>
     */
    private static function methods(ActionMetadataInterface $action): array
    {
        return \array_values(\array_unique(\array_map(\strtolower(...), $action->methods())));
    }

    private static function flatten(string $name): string
    {
        return \strtolower((string) \preg_replace('/[^A-Za-z0-9]/', '', $name));
    }
}
