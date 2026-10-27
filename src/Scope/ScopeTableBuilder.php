<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionScopes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAtomicScopes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopes;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OperationEnumerator;

/**
 * Resolves the scopes every operation of a server needs into a {@see ScopeTable}.
 *
 * An operation's own scopes come from the override its metadata declares, else from the
 * resolver, else none. A related read, `fetchRelated.<rel>` or `fetchRelationship.<rel>`,
 * also needs the read scopes of every type the relation reaches: `fetchCollection` for a
 * to-many, `fetchOne` for a to-one. Every type gets a `fetchOne` and a `fetchCollection`
 * entry even when it exposes neither, because an `?include` hop reaching it is checked
 * against them.
 *
 * The build fails with a `\LogicException` when an override names an operation the type
 * does not have, when a scope is not a token a `WWW-Authenticate` header can carry, when an
 * operation declared public is given scopes, or when the resolver throws.
 */
final class ScopeTableBuilder
{
    /** @var array<string, TypeMetadataInterface>|null */
    private ?array $types = null;

    /** @var array<string, array<string, list<string>>> */
    private array $own = [];

    public function __construct(
        private readonly ServerMetadataInterface $server,
        private readonly ?OperationScopeResolverInterface $resolver = null,
    ) {}

    public function build(): ScopeTable
    {
        $table = [];
        foreach ($this->types() as $name => $type) {
            $keys = $this->keysOf($type);
            $this->assertOverrideKeys($type, $keys);

            foreach ($keys as $keyName => $key) {
                $table[$name][$keyName] = $this->effective($type, $key);
            }
        }

        $atomic = $this->server->atomicOperations() === null ? null : $this->atomic();

        return new ScopeTable($table, $atomic);
    }

    /**
     * Resolves one operation outside a full build, for an entry a cached table is missing.
     * The reads of a type the server does not describe are asked of the resolver by name.
     *
     * @param string|null $type `null` for the Atomic Operations endpoint
     *
     * @return list<string>
     *
     * @throws \LogicException when the server has no such type
     */
    public function resolve(?string $type, OperationKey $key): array
    {
        if ($type === null) {
            return $this->atomic();
        }

        $metadata = $this->types()[$type] ?? null;
        if ($metadata === null) {
            if ($key->kind === OperationKind::FetchOne || $key->kind === OperationKind::FetchCollection) {
                return $this->undescribed($type, $key);
            }

            throw new \LogicException(\sprintf(
                'Cannot resolve the scopes of "%s" on type "%s": the server describes no such type.',
                $key->name(),
                $type,
            ));
        }

        if ($key->kind === OperationKind::Action) {
            $action = $this->actionOf($metadata, $key);
            if ($action !== null) {
                $key = OperationKey::forAction($action, $key->method);
            }
        }

        return $this->effective($metadata, $key);
    }

    /**
     * Whether the server describes `$type`.
     */
    public function hasType(string $type): bool
    {
        return isset($this->types()[$type]);
    }

    /**
     * @return array<string, TypeMetadataInterface>
     */
    private function types(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ($this->server->types() as $type) {
                $this->types[$type->type()] = $type;
            }
        }

        return $this->types;
    }

    /**
     * The operations the table holds for a type: everything it exposes, plus `fetchOne`
     * and `fetchCollection`.
     *
     * @return array<string, OperationKey>
     */
    private function keysOf(TypeMetadataInterface $type): array
    {
        $keys = [];
        foreach (OperationEnumerator::forType($type) as $operation) {
            $keys[$operation->key->name()] = $operation->key;
        }
        foreach ([OperationKind::FetchOne, OperationKind::FetchCollection] as $read) {
            $keys[$read->value] ??= OperationKey::crud($read);
        }

        return $keys;
    }

    /**
     * @param array<string, OperationKey> $keys
     */
    private function assertOverrideKeys(TypeMetadataInterface $type, array $keys): void
    {
        if (!$type instanceof DeclaresOperationScopes) {
            return;
        }

        foreach (\array_keys($type->operationScopes()) as $name) {
            $key = OperationKey::tryFromName((string) $name);
            if ($key === null || !isset($keys[$key->name()])) {
                $names = \array_values(\array_filter(
                    \array_keys($keys),
                    static fn(string $known): bool => !\str_starts_with($known, OperationKind::Action->value . '.'),
                ));

                throw new \LogicException(\sprintf(
                    'The type "%s" declares scopes for the operation "%s", which it does not have. Name one of: %s.',
                    $type->type(),
                    $name,
                    \implode(', ', $names),
                ));
            }
        }
    }

    /**
     * The operation's own scopes, plus for a related read the read scopes of each type it
     * reaches.
     *
     * @return list<string>
     */
    private function effective(TypeMetadataInterface $type, OperationKey $key): array
    {
        $scopes = $this->own($type, $key);

        if ($key->kind !== OperationKind::FetchRelated && $key->kind !== OperationKind::FetchRelationship) {
            return $scopes;
        }

        $relation = $this->relationOf($type, $key);
        if ($relation === null) {
            return $scopes;
        }

        $read = OperationKey::crud($relation->isToMany() ? OperationKind::FetchCollection : OperationKind::FetchOne);
        foreach ($relation->relatedTypes() as $relatedType) {
            $related = $this->types()[$relatedType] ?? null;
            $scopes = [...$scopes, ...($related === null ? $this->undescribed($relatedType, $read) : $this->own($related, $read))];
        }
        $scopes = \array_values(\array_unique($scopes));

        if ($scopes !== [] && $this->isPublic($type, $key)) {
            throw new \LogicException(\sprintf(
                'The operation "%s" of type "%s" is public, but the %s it returns need the scopes %s. A public related read cannot require a scope; make the relation\'s reads secured, or the related reads public.',
                $key->name(),
                $type->type(),
                \count($relation->relatedTypes()) === 1 ? 'resources of type "' . $relation->relatedTypes()[0] . '"' : 'resources of types "' . \implode('", "', $relation->relatedTypes()) . '"',
                \implode(', ', $scopes),
            ));
        }

        return $scopes;
    }

    /**
     * The scopes the operation itself declares or resolves to, memoised.
     *
     * @return list<string>
     */
    private function own(TypeMetadataInterface $type, OperationKey $key): array
    {
        $memo = $key->name();
        if (isset($this->own[$type->type()][$memo])) {
            return $this->own[$type->type()][$memo];
        }

        $scopes = $this->override($type, $key);
        $scopes ??= $this->resolved($type, $key);
        $scopes = $this->validated($scopes, $type->type(), $key);

        if ($scopes !== [] && $this->isPublic($type, $key)) {
            throw new \LogicException(\sprintf(
                'The operation "%s" of type "%s" is public but is given the scopes %s. A public operation cannot require a scope; drop the scopes or secure the operation.',
                $key->name(),
                $type->type(),
                \implode(', ', $scopes),
            ));
        }

        return $this->own[$type->type()][$memo] = $scopes;
    }

    /**
     * The read scopes of a type the server does not describe, such as the target of a
     * linkage-only relation, asked of the resolver by the type's name alone. There is no
     * metadata to override them.
     *
     * @return list<string>
     */
    private function undescribed(string $type, OperationKey $read): array
    {
        $memo = "\0" . $read->name();
        if (isset($this->own[$type][$memo])) {
            return $this->own[$type][$memo];
        }

        $key = $read->withType($type);

        return $this->own[$type][$memo] = $this->validated($this->resolved(null, $key), $type, $key);
    }

    /**
     * @return array<mixed>|null
     */
    private function override(TypeMetadataInterface $type, OperationKey $key): ?array
    {
        if ($key->kind === OperationKind::Action) {
            $action = $this->actionOf($type, $key);

            return $action instanceof DeclaresActionScopes ? $action->scopes() : null;
        }

        if (!$type instanceof DeclaresOperationScopes) {
            return null;
        }

        $declared = $type->operationScopes();

        return \array_key_exists($key->name(), $declared) ? $declared[$key->name()] : null;
    }

    /**
     * @return array<mixed>
     */
    private function resolved(?TypeMetadataInterface $type, OperationKey $key): array
    {
        if ($this->resolver === null) {
            return [];
        }

        try {
            return $this->resolver->requiredScopes($type, $type === null ? $key : $key->withType($type->type()));
        } catch (\Throwable $exception) {
            throw new \LogicException(\sprintf(
                'The scope resolver failed for the operation "%s" of %s: %s',
                $key->name(),
                match (true) {
                    $type !== null => 'type "' . $type->type() . '"',
                    $key->type !== null => 'type "' . $key->type . '"',
                    default => 'the Atomic Operations endpoint',
                },
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * @return list<string>
     */
    private function atomic(): array
    {
        $metadata = $this->server->atomicOperations();
        $key = OperationKey::atomic();
        $scopes = $metadata instanceof DeclaresAtomicScopes ? $metadata->scopes() : null;

        return $this->validated($scopes ?? $this->resolved(null, $key), null, $key);
    }

    /**
     * Checks that each scope is a non-empty token with no whitespace, quote or backslash,
     * so the `WWW-Authenticate` header can carry it as RFC 6750 describes.
     *
     * @param array<mixed> $scopes
     *
     * @return list<string>
     */
    private function validated(array $scopes, ?string $type, OperationKey $key): array
    {
        $valid = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope) || \preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $scope) !== 1) {
                throw new \LogicException(\sprintf(
                    'The operation "%s" of %s requires the scope %s, which is not a scope token. A scope is one or more printable ASCII characters other than a space, a double quote or a backslash.',
                    $key->name(),
                    $type === null ? 'the Atomic Operations endpoint' : 'type "' . $type . '"',
                    \is_string($scope) ? '"' . $scope . '"' : \get_debug_type($scope),
                ));
            }
            $valid[] = $scope;
        }

        return \array_values(\array_unique($valid));
    }

    /**
     * Whether the metadata declares the operation public: a CRUD operation listed in
     * {@see TypeMetadataInterface::publicOperations()}, or a relation's endpoint whose
     * relation security is `false`, or which inherits from a public parent operation.
     */
    private function isPublic(TypeMetadataInterface $type, OperationKey $key): bool
    {
        $crud = $key->kind->operationType();
        if ($crud !== null) {
            return \in_array($crud, $type->publicOperations(), true);
        }

        if (!$key->kind->takesRelation()) {
            return false;
        }

        $relation = $this->relationOf($type, $key);
        if ($relation === null) {
            return false;
        }

        $reads = $key->kind === OperationKind::FetchRelated || $key->kind === OperationKind::FetchRelationship;
        $security = $reads ? $relation->securityRead() : $relation->securityMutate();
        if ($security !== null) {
            return $security === false;
        }

        return \in_array($reads ? OperationType::FetchOne : OperationType::Update, $type->publicOperations(), true);
    }

    private function relationOf(TypeMetadataInterface $type, OperationKey $key): ?RelationMetadataInterface
    {
        foreach ($type->relations() as $relation) {
            if ($relation->name() === $key->relation) {
                return $relation;
            }
        }

        return null;
    }

    private function actionOf(TypeMetadataInterface $type, OperationKey $key): ?ActionMetadataInterface
    {
        foreach ($type->actions() as $action) {
            if ($action->path() === $key->actionPath && ($key->actionScope === null || $action->scope() === $key->actionScope)) {
                return $action;
            }
        }

        return null;
    }
}
