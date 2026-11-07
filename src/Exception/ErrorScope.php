<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * The operations an error can arrive on, when that is not all of them. An error declares
 * it on its {@see ErrorDescriptor}, and a {@see ScopedErrorSource} can give a class it
 * catalogues another. The OpenAPI projection offers a scoped code only on the operations
 * its scope covers.
 *
 * A scope names operations of a type by name, in the grammar
 * {@see OperationKey::name()} writes (`update`, `fetchRelated.author`,
 * `action.publish.post`), or every operation of a type when it names none; or operations
 * of every type by {@see OperationKind}. {@see and()} combines scopes.
 */
final readonly class ErrorScope
{
    /**
     * @param array<string, list<string>> $operations type → operation names, `[]` for every operation of the type
     * @param list<OperationKind>         $kinds
     */
    private function __construct(
        public array $operations = [],
        public array $kinds = [],
    ) {}

    /**
     * The named operations of `$type`, or all of its operations when none is named.
     *
     * @throws \InvalidArgumentException when a name is not an operation name
     */
    public static function on(string $type, string ...$operations): self
    {
        foreach ($operations as $operation) {
            if (OperationKey::tryFromName($operation) === null && \preg_match('/^action\..+\.(get|post|patch|put|delete)$/', $operation) !== 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'An error scope names the operation "%s" of the type "%s", which is not an operation name. Name a CRUD, related or relationship operation (`update`, `fetchRelated.author`) or an action (`action.<path>.<method>`).',
                    $operation,
                    $type,
                ));
            }
        }

        return new self([$type => \array_values(\array_unique($operations))]);
    }

    /**
     * Every operation of these kinds, on any type.
     */
    public static function kinds(OperationKind ...$kinds): self
    {
        return new self(kinds: \array_values($kinds));
    }

    /**
     * The operations either scope covers.
     */
    public function and(self $other): self
    {
        $operations = $this->operations;
        foreach ($other->operations as $type => $names) {
            $operations[$type] = isset($operations[$type]) && $operations[$type] !== [] && $names !== []
                ? \array_values(\array_unique([...$operations[$type], ...$names]))
                : [];
        }

        return new self($operations, \array_values(\array_unique([...$this->kinds, ...$other->kinds], \SORT_REGULAR)));
    }

    /**
     * Whether `$operation` of `$type` is one this scope covers.
     */
    public function covers(string $type, OperationKey $operation): bool
    {
        if (\in_array($operation->kind, $this->kinds, true)) {
            return true;
        }
        $names = $this->operations[$type] ?? null;

        return $names !== null && ($names === [] || \in_array($operation->name(), $names, true));
    }
}
