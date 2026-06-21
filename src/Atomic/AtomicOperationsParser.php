<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

use haddowg\JsonApi\Exception\AtomicOperationsInvalid;

/**
 * Parses a decoded Atomic Operations request document into an ordered list of
 * {@see OperationDescriptor}s.
 *
 * The parser is purely **structural**: it validates the document carries an
 * `atomic:operations` array, that each operation has a known `op`, exactly one of
 * `ref`/`href`, a structurally valid `ref` (a required `type`, exactly one of
 * `id`/`lid`, an optional `relationship`), and a `data` shape appropriate to the
 * operation. Each failure throws an {@see AtomicOperationsInvalid} whose error
 * carries a `source.pointer` locating the offending member.
 *
 * Semantic validation — whether the `type` is registered, whether the named
 * relationship exists, whether a `lid` resolves — is **out of scope** here: that is
 * execution-time work the executor performs against the server's registry. The
 * parser never touches the storage or registry; it can run in core alone.
 *
 * @see https://jsonapi.org/ext/atomic/#operation-objects
 */
final class AtomicOperationsParser
{
    private const string OPERATIONS_POINTER = '/' . AtomicExtension::OPERATIONS_MEMBER;

    /**
     * @return list<OperationDescriptor>
     *
     * @throws AtomicOperationsInvalid on any structural failure
     */
    public function parse(mixed $document): array
    {
        if (!\is_array($document) || \array_is_list($document)) {
            throw new AtomicOperationsInvalid(
                'The atomic operations request document must be an object.',
                '',
            );
        }

        if (!\array_key_exists(AtomicExtension::OPERATIONS_MEMBER, $document)) {
            throw new AtomicOperationsInvalid(
                "The atomic operations request document must carry an '" . AtomicExtension::OPERATIONS_MEMBER . "' member.",
                '',
            );
        }

        $operations = $document[AtomicExtension::OPERATIONS_MEMBER];
        if (!\is_array($operations) || !\array_is_list($operations)) {
            throw new AtomicOperationsInvalid(
                "The '" . AtomicExtension::OPERATIONS_MEMBER . "' member must be an array.",
                self::OPERATIONS_POINTER,
            );
        }

        if ($operations === []) {
            throw new AtomicOperationsInvalid(
                "The '" . AtomicExtension::OPERATIONS_MEMBER . "' member must contain at least one operation.",
                self::OPERATIONS_POINTER,
            );
        }

        $descriptors = [];
        foreach ($operations as $index => $operation) {
            $descriptors[] = $this->parseOperation($operation, $index);
        }

        return $descriptors;
    }

    /**
     * @throws AtomicOperationsInvalid
     */
    private function parseOperation(mixed $operation, int $index): OperationDescriptor
    {
        $pointer = self::OPERATIONS_POINTER . '/' . $index;

        if (!\is_array($operation) || \array_is_list($operation)) {
            throw new AtomicOperationsInvalid('Each atomic operation must be an object.', $pointer);
        }

        $opCode = $this->parseOpCode($operation, $pointer);
        [$ref, $href] = $this->parseTarget($operation, $pointer);

        // An `add` that creates a resource is the one operation allowed to omit a
        // target entirely — its target is derived from `data.type`. Every other
        // operation (an `update`/`remove`, or any relationship operation) must
        // identify its target by a `ref` or `href`.
        if ($ref === null && $href === null && $opCode !== AtomicOperationCode::Add) {
            throw new AtomicOperationsInvalid(
                "An atomic operation must carry exactly one of 'ref' or 'href'.",
                $pointer,
            );
        }

        $data = $this->parseData($operation, $opCode, $ref, $pointer);

        return new OperationDescriptor($opCode, $ref, $href, $data, $index);
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @throws AtomicOperationsInvalid
     */
    private function parseOpCode(array $operation, string $pointer): AtomicOperationCode
    {
        if (!\array_key_exists('op', $operation)) {
            throw new AtomicOperationsInvalid("An atomic operation must carry an 'op' member.", $pointer . '/op');
        }

        $op = $operation['op'];
        if (!\is_string($op)) {
            throw new AtomicOperationsInvalid("The 'op' member must be a string.", $pointer . '/op');
        }

        return AtomicOperationCode::fromString($op, $pointer . '/op');
    }

    /**
     * Parses the operation's target — at most one of a structural `ref` or a `href`
     * string. Both present is always invalid; neither present yields `[null, null]`
     * (valid only for a resource `add`, enforced by the caller).
     *
     * @param array<array-key, mixed> $operation
     *
     * @return array{0: ?Ref, 1: ?string}
     *
     * @throws AtomicOperationsInvalid
     */
    private function parseTarget(array $operation, string $pointer): array
    {
        $hasRef = \array_key_exists('ref', $operation);
        $hasHref = \array_key_exists('href', $operation);

        if ($hasRef && $hasHref) {
            throw new AtomicOperationsInvalid(
                "An atomic operation must carry exactly one of 'ref' or 'href', not both.",
                $pointer,
            );
        }

        if ($hasHref) {
            $href = $operation['href'];
            if (!\is_string($href) || $href === '') {
                throw new AtomicOperationsInvalid("The 'href' member must be a non-empty string.", $pointer . '/href');
            }

            return [null, $href];
        }

        if ($hasRef) {
            return [$this->parseRef($operation['ref'], $pointer . '/ref'), null];
        }

        return [null, null];
    }

    /**
     * @throws AtomicOperationsInvalid
     */
    private function parseRef(mixed $ref, string $pointer): Ref
    {
        if (!\is_array($ref) || \array_is_list($ref)) {
            throw new AtomicOperationsInvalid("The 'ref' member must be an object.", $pointer);
        }

        if (!isset($ref['type']) || !\is_string($ref['type']) || $ref['type'] === '') {
            throw new AtomicOperationsInvalid("A 'ref' must carry a non-empty 'type'.", $pointer . '/type');
        }

        $id = $this->stringMemberOrNull($ref, 'id', $pointer);
        $lid = $this->stringMemberOrNull($ref, 'lid', $pointer);

        if ($id !== null && $lid !== null) {
            throw new AtomicOperationsInvalid("A 'ref' must carry at most one of 'id' or 'lid'.", $pointer);
        }

        if ($id === null && $lid === null) {
            throw new AtomicOperationsInvalid("A 'ref' must identify its resource by 'id' or 'lid'.", $pointer);
        }

        $relationship = $this->stringMemberOrNull($ref, 'relationship', $pointer);

        return new Ref($ref['type'], $id, $lid, $relationship);
    }

    /**
     * Reads an optional string member of a `ref`, normalising an absent or `null`
     * member to `null` and rejecting a non-string or empty-string one — an empty
     * `relationship`, for instance, must not silently demote a relationship
     * operation to a resource operation.
     *
     * @param array<array-key, mixed> $ref
     *
     * @throws AtomicOperationsInvalid
     */
    private function stringMemberOrNull(array $ref, string $member, string $pointer): ?string
    {
        if (!\array_key_exists($member, $ref) || $ref[$member] === null) {
            return null;
        }

        if (!\is_string($ref[$member]) || $ref[$member] === '') {
            throw new AtomicOperationsInvalid("The '$member' member of a 'ref' must be a non-empty string.", $pointer . '/' . $member);
        }

        return $ref[$member];
    }

    /**
     * Validates the operation's `data` shape against its code and target, returning
     * the `data` verbatim for the executor to interpret.
     *
     * The structural rule the parser enforces:
     * - a `remove` of a resource (no `relationship` ref) carries no `data`;
     * - every other operation carries `data` (a resource object, a single
     *   identifier or `null` for a to-one, or a list of identifiers for a to-many);
     * - when present, `data` must be an array (object or list) or `null` — a scalar
     *   `data` is never valid.
     *
     * Distinguishing a resource object from an identifier, and a to-one from a
     * to-many, is the executor's job (it resolves the target's cardinality); the
     * parser only rejects shapes that cannot be either.
     *
     * @param array<array-key, mixed> $operation
     *
     * @throws AtomicOperationsInvalid
     */
    private function parseData(array $operation, AtomicOperationCode $opCode, ?Ref $ref, string $pointer): mixed
    {
        $hasData = \array_key_exists('data', $operation);
        $isRelationship = $ref !== null && $ref->hasRelationship();

        if ($opCode === AtomicOperationCode::Remove && !$isRelationship) {
            if ($hasData) {
                throw new AtomicOperationsInvalid(
                    "A 'remove' operation on a resource must not carry a 'data' member.",
                    $pointer . '/data',
                );
            }

            return null;
        }

        if (!$hasData) {
            throw new AtomicOperationsInvalid("This atomic operation must carry a 'data' member.", $pointer . '/data');
        }

        $data = $operation['data'];
        if ($data !== null && !\is_array($data)) {
            throw new AtomicOperationsInvalid(
                "The 'data' member must be a resource object, a resource identifier, an array of identifiers, or null.",
                $pointer . '/data',
            );
        }

        return $data;
    }
}
