<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

/**
 * The scopes every operation of a server needs, resolved once by
 * {@see ScopeTableBuilder}. Each list is the effective one: a related or relationship
 * read already includes the read scopes of the type it returns.
 *
 * It holds only strings, so an integration can cache it with {@see toArray()} and load it
 * back with {@see fromArray()}.
 */
final readonly class ScopeTable
{
    /**
     * @param array<string, array<string, list<string>>> $types  type → operation key name → required scopes
     * @param list<string>|null                          $atomic the Atomic Operations endpoint's scopes, `null` when the server has none
     */
    public function __construct(
        public array $types = [],
        public ?array $atomic = null,
    ) {}

    /**
     * The scopes one operation needs, or `null` when the table has no entry for it.
     *
     * @param string|null $type `null` for the Atomic Operations endpoint
     *
     * @return list<string>|null
     */
    public function for(?string $type, string $operation): ?array
    {
        if ($type === null) {
            return $this->atomic;
        }

        return $this->types[$type][$operation] ?? null;
    }

    /**
     * @return array{types: array<string, array<string, list<string>>>, atomic: list<string>|null}
     */
    public function toArray(): array
    {
        return ['types' => $this->types, 'atomic' => $this->atomic];
    }

    /**
     * Loads a table {@see toArray()} wrote.
     *
     * @param array<mixed> $table
     *
     * @throws \InvalidArgumentException when `$table` is not one
     */
    public static function fromArray(array $table): self
    {
        $types = $table['types'] ?? null;
        $atomic = $table['atomic'] ?? null;
        if (!\is_array($types) || ($atomic !== null && !self::isScopeList($atomic))) {
            throw new \InvalidArgumentException('A scope table needs a "types" map and an "atomic" list or null.');
        }

        $loaded = [];
        foreach ($types as $type => $operations) {
            if (!\is_string($type) || !\is_array($operations)) {
                throw new \InvalidArgumentException('A scope table maps each type name to its operations.');
            }
            foreach ($operations as $operation => $scopes) {
                if (!\is_string($operation) || !self::isScopeList($scopes)) {
                    throw new \InvalidArgumentException(\sprintf('The scope table entry for type "%s" must map operation names to lists of scopes.', $type));
                }
                $loaded[$type][$operation] = $scopes;
            }
        }

        /** @var list<string>|null $atomic */
        return new self($loaded, $atomic);
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isScopeList(mixed $value): bool
    {
        if (!\is_array($value) || !\array_is_list($value)) {
            return false;
        }
        foreach ($value as $scope) {
            if (!\is_string($scope)) {
                return false;
            }
        }

        return true;
    }
}
