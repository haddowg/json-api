<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\Scope\ScopeTable;

/**
 * Builds a scope table for every operation a server's metadata projects, from a rule,
 * without the build-time checks {@see \haddowg\JsonApi\Scope\ScopeTableBuilder} applies,
 * so a projection test can give any operation any scopes.
 */
final class ScopeTables
{
    /**
     * @param \Closure(string, OperationKey): list<string> $rule   type, operation → required scopes
     * @param list<string>                                 $atomic the Atomic Operations endpoint's scopes
     */
    public static function of(ServerMetadataInterface $server, \Closure $rule, array $atomic = []): ScopeTable
    {
        $types = [];
        foreach ($server->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $operation) {
                $types[$type->type()][$operation->key->name()] = $rule($type->type(), $operation->key);
            }
            foreach ([OperationKind::FetchOne, OperationKind::FetchCollection] as $read) {
                $types[$type->type()][$read->value] ??= $rule($type->type(), OperationKey::crud($read));
            }
        }

        return new ScopeTable($types, $server->atomicOperations() === null ? null : $atomic);
    }
}
