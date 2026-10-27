<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Scope;

use haddowg\JsonApi\Exception\InsufficientScope;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\Operation\AddToRelationshipOperation;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\CreateResourceOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\FetchRelatedOperation;
use haddowg\JsonApi\Operation\FetchRelationshipOperation;
use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\RemoveFromRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateRelationshipOperation;
use haddowg\JsonApi\Operation\UpdateResourceOperation;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Refuses a request whose access token does not grant the scopes its operation needs.
 *
 * {@see \haddowg\JsonApi\Server\Server::withScopeEnforcement()} runs {@see enforce()} on
 * every operation after query validation and before the serving hooks and the handler.
 * It checks the operation's own scopes, then every hop of each `?include` path: a to-many
 * hop needs the `fetchCollection` scopes of each type it reaches and a to-one hop their
 * `fetchOne` scopes. While rendering, {@see canInclude()} drops an included resource the
 * caller cannot read, which covers default includes and hops the up-front walk could not
 * see through.
 *
 * Enforcement is skipped for an operation dispatched with no HTTP request and for a request
 * the {@see GrantedScopesProviderInterface} reports no grant for. Scopes only restrict: the
 * application's own security still applies after the check.
 */
final class ScopeEnforcer
{
    /** @var \WeakMap<object, list<string>|null> */
    private \WeakMap $grants;

    /** @var array<string, list<string>> */
    private array $resolvedLive = [];

    private ?ScopeTable $table;

    private readonly ScopeChecker $checker;

    public function __construct(
        private readonly GrantedScopesProviderInterface $granted,
        private readonly ScopeTableBuilder $builder,
        ?ScopeTable $table = null,
        ScopeMatcherInterface $matcher = new ExactScopeMatcher(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->table = $table;
        $this->checker = new ScopeChecker($matcher);
        $this->grants = new \WeakMap();
    }

    /**
     * The enforcer a server renders with, or `null` when it enforces no scopes.
     */
    public static function forServer(\haddowg\JsonApi\Server\ServerInterface $server): ?self
    {
        return $server instanceof \haddowg\JsonApi\Server\Server ? $server->scopeEnforcer() : null;
    }

    /**
     * The scope table, built on first use when none was given.
     */
    public function table(): ScopeTable
    {
        return $this->table ??= $this->builder->build();
    }

    /**
     * Checks the operation's scopes and those of every `?include` hop it renders.
     *
     * @throws InsufficientScope
     */
    public function enforce(JsonApiOperationInterface $operation, SerializerResolverInterface $resources): void
    {
        $request = $operation->context()->httpRequest();
        if ($request === null) {
            return;
        }

        $granted = $this->grantedFor($request);
        if ($granted === null) {
            return;
        }

        [$type, $key] = self::keyFor($operation);
        $this->assertGranted($granted, $this->required($type, $key));

        $roots = $this->includeRoots($operation, $resources);
        if ($roots !== null) {
            $this->enforceIncludes(
                $request instanceof JsonApiRequestInterface ? $request : new JsonApiRequest($request),
                $roots,
                $granted,
                $resources,
            );
        }
    }

    /**
     * Checks every operation of an Atomic Operations batch against the batch request's
     * grant, before any of them runs. Call it before the transaction begins, so a refused
     * batch writes nothing. A failure names the entry by its `source.pointer`.
     *
     * @param iterable<int, array{string, OperationKey}> $operations batch index → the type and operation it performs
     *
     * @throws InsufficientScope
     */
    public function enforceBatch(ServerRequestInterface $request, iterable $operations): void
    {
        $granted = $this->grantedFor($request);
        if ($granted === null) {
            return;
        }

        foreach ($operations as $index => [$type, $key]) {
            $required = $this->required($type, $key);
            $missing = $this->missing($granted, $required);
            if ($missing !== []) {
                throw new InsufficientScope($required, $missing, pointer: '/atomic:operations/' . $index);
            }
        }
    }

    /**
     * Whether the caller may read a resource of `$type` reached through a relationship:
     * the type's `fetchCollection` scopes for a to-many, its `fetchOne` scopes for a
     * to-one. A request with no grant may read anything.
     */
    public function canInclude(ServerRequestInterface $request, string $type, bool $toMany): bool
    {
        $granted = $this->grantedFor($request);
        if ($granted === null) {
            return true;
        }

        return $this->missing($granted, $this->required($type, OperationKey::crud($toMany ? OperationKind::FetchCollection : OperationKind::FetchOne))) === [];
    }

    /**
     * The scopes the request's token grants, read once per request object.
     *
     * @return list<string>|null
     */
    public function grantedFor(ServerRequestInterface $request): ?array
    {
        if (!$this->grants->offsetExists($request)) {
            $this->grants[$request] = $this->granted->granted($request);
        }

        return $this->grants[$request];
    }

    /**
     * The scopes one operation needs. An operation the table has no entry for is resolved
     * now and remembered, with a warning, since the table is out of date.
     *
     * @param string|null $type `null` for the Atomic Operations endpoint
     *
     * @return list<string>
     */
    public function required(?string $type, OperationKey $key): array
    {
        $scopes = $this->table()->for($type, $key->name());
        if ($scopes !== null) {
            return $scopes;
        }

        $memo = ($type ?? '') . "\0" . $key->name();
        if (!isset($this->resolvedLive[$memo]) && $type !== null && !$this->builder->hasType($type)) {
            // A type the server does not describe is never in the table; its reads are asked by name.
            $this->resolvedLive[$memo] = $this->builder->resolve($type, $key);
        }
        if (!isset($this->resolvedLive[$memo])) {
            $this->logger->warning('The scope table has no entry for "{operation}" on {type}; resolved it now. Rebuild the table.', [
                'operation' => $key->name(),
                'type' => $type === null ? 'the Atomic Operations endpoint' : 'type "' . $type . '"',
            ]);
            $this->resolvedLive[$memo] = $this->builder->resolve($type, $key);
        }

        return $this->resolvedLive[$memo];
    }

    /**
     * The required scopes the grant does not cover, by the configured matcher.
     *
     * @param list<string> $granted
     * @param list<string> $required
     *
     * @return list<string>
     */
    public function missing(array $granted, array $required): array
    {
        return $this->checker->missing($granted, $required);
    }

    /**
     * The type an operation acts on, `null` for an Atomic Operations batch, and its key.
     *
     * @return array{string|null, OperationKey}
     */
    public static function keyFor(JsonApiOperationInterface $operation): array
    {
        if ($operation instanceof AtomicOperationsOperation) {
            return [null, OperationKey::atomic()];
        }

        $target = $operation->target();
        $relationship = (string) $target->relationship;

        $key = match (true) {
            $operation instanceof FetchResourceOperation => OperationKey::crud($target->hasId() ? OperationKind::FetchOne : OperationKind::FetchCollection),
            $operation instanceof CreateResourceOperation => OperationKey::crud(OperationKind::Create),
            $operation instanceof UpdateResourceOperation => OperationKey::crud(OperationKind::Update),
            $operation instanceof DeleteResourceOperation => OperationKey::crud(OperationKind::Delete),
            $operation instanceof FetchRelatedOperation => OperationKey::relation(OperationKind::FetchRelated, $relationship),
            $operation instanceof FetchRelationshipOperation => OperationKey::relation(OperationKind::FetchRelationship, $relationship),
            $operation instanceof UpdateRelationshipOperation => OperationKey::relation(OperationKind::UpdateRelationship, $relationship),
            $operation instanceof AddToRelationshipOperation => OperationKey::relation(OperationKind::AddRelationship, $relationship),
            $operation instanceof RemoveFromRelationshipOperation => OperationKey::relation(OperationKind::RemoveRelationship, $relationship),
            $operation instanceof CustomActionOperation => OperationKey::action(
                $operation->action(),
                $target->hasId() ? ActionScope::Resource : ActionScope::Collection,
                $operation->method(),
            ),
            default => throw new \LogicException(\sprintf('Cannot tell which operation %s performs, so its scopes cannot be checked.', $operation::class)),
        };

        return [$target->type, $key];
    }

    /**
     * @param list<string> $granted
     * @param list<string> $required
     *
     * @throws InsufficientScope
     */
    private function assertGranted(array $granted, array $required, ?string $include = null): void
    {
        $missing = $this->missing($granted, $required);
        if ($missing !== []) {
            throw new InsufficientScope($required, $missing, $include);
        }
    }

    /**
     * The types an operation's `?include` paths start from, or `null` when it renders no
     * include tree core can walk.
     *
     * @return list<string>|null
     */
    private function includeRoots(JsonApiOperationInterface $operation, SerializerResolverInterface $resources): ?array
    {
        $target = $operation->target();

        if ($operation instanceof FetchResourceOperation
            || $operation instanceof CreateResourceOperation
            || $operation instanceof UpdateResourceOperation) {
            return [$target->type];
        }

        if (!$operation instanceof FetchRelatedOperation || $target->relationship === null) {
            return null;
        }

        $serializer = $resources->hasSerializerFor($target->type) ? $resources->serializerFor($target->type) : null;
        $relation = $serializer instanceof AbstractResource ? $serializer->relationNamed($target->relationship) : null;

        return $relation?->relatedTypes();
    }

    /**
     * Walks each `?include` path hop by hop and refuses the request at the first hop whose
     * types the caller may not read. A hop through a type whose relations core cannot see
     * is left to {@see canInclude()} at render time.
     *
     * @param list<string> $roots
     * @param list<string> $granted
     *
     * @throws InsufficientScope
     */
    private function enforceIncludes(JsonApiRequestInterface $request, array $roots, array $granted, SerializerResolverInterface $resources): void
    {
        foreach ($request->getIncludePaths() as $path) {
            $types = $roots;
            $walked = '';

            foreach (\explode('.', $path) as $segment) {
                $walked = $walked === '' ? $segment : $walked . '.' . $segment;
                $required = [];
                $next = [];

                foreach ($types as $type) {
                    $serializer = $resources->hasSerializerFor($type) ? $resources->serializerFor($type) : null;
                    if (!$serializer instanceof AbstractResource) {
                        $this->logger->debug('Cannot check the scopes of the include path "{path}" past type "{type}", whose relations are not declared on a resource. Resources it reaches that the caller may not read are dropped from the response.', [
                            'path' => $path,
                            'type' => $type,
                        ]);

                        continue 3;
                    }

                    $relation = $serializer->relationNamed($segment);
                    if ($relation === null) {
                        continue;
                    }

                    $read = OperationKey::crud($relation->isToMany() ? OperationKind::FetchCollection : OperationKind::FetchOne);
                    foreach ($relation->relatedTypes() as $relatedType) {
                        $required = [...$required, ...$this->required($relatedType, $read)];
                        $next[$relatedType] = true;
                    }
                }

                $this->assertGranted($granted, \array_values(\array_unique($required)), $walked);

                $types = \array_keys($next);
                if ($types === []) {
                    continue 2;
                }
            }
        }
    }
}
