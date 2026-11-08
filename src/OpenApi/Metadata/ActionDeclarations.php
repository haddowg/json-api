<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\VendorExtensions;
use haddowg\JsonApi\Resource\Filter\FilterInterface;

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
 * - an action path may not read as one of the operation-id words in {@see RESERVED_NAMES};
 * - no two of the type's action ids may flatten to the same name;
 * - an action may not be declared both read-only and destructive;
 * - the action's own vendor extension names must be valid;
 * - only a collection-scope action may take its type's filters, and each filter key it
 *   lists must be one the type declares.
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
     * The filter keys are checked only when `$filterKeys` is given, so an integration
     * that registers actions before it has built the type's filters can still check the
     * rest; {@see validateType()} always passes them.
     *
     * @param iterable<ActionMetadataInterface> $actions
     * @param list<string>|null                 $filterKeys the keys of the filters the type declares
     *
     * @throws \LogicException naming the type and the action at fault
     */
    public static function validate(string $type, iterable $actions, ?array $filterKeys = null): void
    {
        $reserved = [];
        foreach (self::RESERVED_NAMES as $name) {
            $reserved[self::flatten($name)] = 'the "' . $name . '" operation';
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

            if ($action instanceof DeclaresActionFilters) {
                self::validateFilters($type, $action, $filterKeys);
            }
        }
    }

    /**
     * {@see validate()} for a type's metadata.
     *
     * @throws \LogicException
     */
    public static function validateType(TypeMetadataInterface $type): void
    {
        self::validate($type->type(), $type->actions(), self::filterKeys($type->filters()));
    }

    /**
     * The filters the action takes from its mount type, in the order the type declares
     * them: none unless it is a collection-scope action implementing
     * {@see DeclaresActionFilters}, every filter the type
     * declares for `true`, and the named ones for a list. The projector documents these
     * and an adapter applies these, so the two never disagree.
     *
     * @return list<FilterInterface>
     */
    public static function filters(TypeMetadataInterface $type, ActionMetadataInterface $action): array
    {
        $declared = $action instanceof DeclaresActionFilters && $action->scope() === ActionScope::Collection ? $action->filters() : false;
        if ($declared === false || $declared === []) {
            return [];
        }
        if ($declared === true) {
            return $type->filters();
        }

        return \array_values(\array_filter(
            $type->filters(),
            static fn(FilterInterface $filter): bool => \in_array($filter->key(), $declared, true),
        ));
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
        return OperationSafety::of($action)?->toArray() ?? [];
    }

    /**
     * @param list<string>|null $filterKeys
     *
     * @throws \LogicException
     */
    private static function validateFilters(string $type, DeclaresActionFilters $action, ?array $filterKeys): void
    {
        $declared = $action->filters();
        if ($declared === false || $declared === []) {
            return;
        }

        if ($action->scope() !== ActionScope::Collection) {
            throw new \LogicException(\sprintf(
                'The "%s" action on type "%s" takes the type\'s filters, but it is mounted on a single resource. Only a collection-scope action may take filters.',
                $action->path(),
                $type,
            ));
        }

        if ($declared === true || $filterKeys === null) {
            return;
        }

        foreach ($declared as $key) {
            if (!\in_array($key, $filterKeys, true)) {
                throw new \LogicException(\sprintf(
                    'The "%s" action on type "%s" takes the filter "%s", which the type does not declare. Declare it on the type or drop it from the action; the type declares %s.',
                    $action->path(),
                    $type,
                    $key,
                    $filterKeys === [] ? 'no filters' : '"' . \implode('", "', $filterKeys) . '"',
                ));
            }
        }
    }

    /**
     * @param list<FilterInterface> $filters
     *
     * @return list<string>
     */
    private static function filterKeys(array $filters): array
    {
        return \array_map(static fn(FilterInterface $filter): string => $filter->key(), $filters);
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
