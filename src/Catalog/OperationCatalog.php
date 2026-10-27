<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopeTable;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OperationEnumerator;
use haddowg\JsonApi\Scope\ScopeTable;

/**
 * Every operation a server exposes, each as an {@see OperationDefinition}: the same
 * operations, in the same order, that its OpenAPI document projects.
 *
 * It is a view over metadata the integration already has. The operations come from the
 * server's {@see ServerMetadataInterface}, read when the catalogue is first iterated, their
 * security rules from an {@see OperationSecuritySourceInterface}, and their scopes from the
 * scope table the metadata declares through {@see DeclaresOperationScopeTable}. Nothing is
 * compiled or cached here.
 */
final class OperationCatalog
{
    /**
     * @param \Closure(string): ServerMetadataInterface $metadata server name → that server's metadata
     */
    public function __construct(
        private readonly \Closure $metadata,
        private readonly OperationSecuritySourceInterface $security,
    ) {}

    /**
     * The server's operations, built one at a time as they are iterated.
     *
     * @return \Generator<int, OperationDefinition>
     *
     * @throws \LogicException when the metadata declares a scope table with no entry for an operation
     */
    public function forServer(string $server): \Generator
    {
        $metadata = ($this->metadata)($server);
        $table = self::scopeTable($metadata);

        foreach ($metadata->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $operation) {
                $key = $operation->key->withType($type->type());

                yield $this->definition(
                    $server,
                    $type,
                    $key,
                    $operation->operationId,
                    $operation->path,
                    $operation->tags,
                    self::scopes($table, $type->type(), $key),
                );
            }
        }

        $atomic = $metadata->atomicOperations();
        if ($atomic !== null) {
            $key = OperationKey::atomic();

            yield $this->definition($server, null, $key, 'atomic.operations', $atomic->path(), [$atomic->tag()], self::scopes($table, null, $key));
        }
    }

    /**
     * @param list<string> $tags
     * @param list<string> $scopes
     */
    private function definition(
        string $server,
        ?TypeMetadataInterface $type,
        OperationKey $key,
        string $operationId,
        string $path,
        array $tags,
        array $scopes,
    ): OperationDefinition {
        $security = $this->security->securityOf($server, $type, $key);

        return new OperationDefinition(
            server: $server,
            key: $key,
            operationId: $operationId,
            type: $type?->type(),
            kind: $key->kind,
            method: $key->method,
            pathTemplate: $path,
            relation: $key->relation,
            actionPath: $key->actionPath,
            actionScope: $key->actionScope,
            tags: $tags,
            security: $security->expression,
            securityLevel: $security->level,
            requiredScopes: $scopes,
            safety: $key->safety,
        );
    }

    /**
     * The table the metadata declares, or `null` when it declares none or an empty one,
     * which both mean no operation needs a scope.
     */
    private static function scopeTable(ServerMetadataInterface $metadata): ?ScopeTable
    {
        if (!$metadata instanceof DeclaresOperationScopeTable) {
            return null;
        }

        $table = $metadata->scopeTable();

        return $table->types === [] && $table->atomic === null ? null : $table;
    }

    /**
     * @return list<string>
     */
    private static function scopes(?ScopeTable $table, ?string $type, OperationKey $key): array
    {
        if ($table === null) {
            return [];
        }

        return $table->for($type, $key->name()) ?? throw new \LogicException(\sprintf(
            'The scope table has no entry for the operation "%s" of %s. Rebuild the table from the same metadata.',
            $key->name(),
            $type === null ? 'the Atomic Operations endpoint' : 'type "' . $type . '"',
        ));
    }
}
