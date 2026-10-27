# OAuth scopes

A server can refuse a request whose access token does not grant the OAuth scopes the
operation needs. Core treats a scope as an opaque string: it has no grammar, hierarchy or
wildcards unless you plug in a matcher that adds them. Your framework still authenticates
the caller and runs its own security rules. Scopes only restrict, so a request that passes
the scope check can still be denied by them.

The check needs four things from you, all optional except the grant provider:

| Contract | What it does |
|---|---|
| `Scope\GrantedScopesProviderInterface` | Reads the scopes the request's token grants. Return `null` when the request carries no scoped token, and the check is skipped for it. |
| `Scope\OperationScopeResolverInterface` | Says which scopes an operation needs. Without one, an operation needs none unless its metadata overrides it. |
| `Scope\ScopeMatcherInterface` | Decides whether the granted scopes cover a required one. The default `ExactScopeMatcher` compares exact strings. |
| `Scope\ScopeCatalogInterface` | Lists every scope with a description, for documentation. |

## Resolving the scopes

`ScopeTableBuilder` asks the resolver once per operation of every type in your
`ServerMetadataInterface` and returns a `ScopeTable`. The resolver receives the type's
metadata and an [`OperationKey`](openapi.md#operation-examples): its `OperationKind`, the
type it acts on in `$type`, the relation or action, the HTTP method, and for an action the
safety it declares.

A relation can reach a type the server does not describe, such as the target of a
linkage-only relationship. The resolver is then asked for that type's `fetchOne` or
`fetchCollection` scopes with `null` metadata, and the key's `$type` names it, so it can
answer by name. Returning `[]` adds nothing. The Atomic Operations endpoint is the other
call with `null` metadata; its key's kind is `OperationKind::Atomic` and it names no type.

```php
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

final class ReadWriteScopes implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($type === null) {
            return ['batch'];            // the Atomic Operations endpoint
        }

        $reads = $operation->method === 'GET' || $operation->safety?->readOnly === true;

        return [$type->type() . ($reads ? ':read' : ':write')];
    }
}
```

Metadata can override the resolver. Type metadata implementing `DeclaresOperationScopes`
returns a map keyed like `operationExamples`, such as `fetchOne` or `updateRelationship.tags`.
Action metadata implements `DeclaresActionScopes` and Atomic Operations metadata
`DeclaresAtomicScopes`. An override of `[]` means the operation needs no scope. Type
metadata implementing `DeclaresResourceClass` names its class, so a resolver can read your
own attributes off it.

The table follows these rules:

- A related read, `fetchRelated.<rel>` or `fetchRelationship.<rel>`, needs its own scopes
  plus the read scopes of every type the relation reaches: `fetchCollection` for a
  to-many, `fetchOne` for a to-one, and every member type of a polymorphic relation.
- Every type gets `fetchOne` and `fetchCollection` entries even when it exposes neither,
  because an `?include` hop reaching it is checked against them.
- A create or update carrying `data.relationships` needs only its own scopes.

The build throws a `\LogicException` when an override names an operation the type does not
have, when a scope contains whitespace, a double quote or a backslash, when the resolver
throws, or when an operation declared public needs a scope. Public means listed in
`publicOperations()`, or a relation endpoint whose relation security is `false`, or which
inherits a public parent operation. A public related read fails too when the types it
returns need read scopes, because it would need them as well.

The table holds only strings, so you can cache it with `toArray()` and load it with
`ScopeTable::fromArray()`.

## Turning enforcement on

```php
use haddowg\JsonApi\Scope\ScopeEnforcer;
use haddowg\JsonApi\Scope\ScopeTableBuilder;

$enforcer = new ScopeEnforcer(
    granted: new BearerTokenScopes(),
    builder: new ScopeTableBuilder($metadata, new ReadWriteScopes()),
    table: $cachedTable,          // optional; built on first use when null
    logger: $logger,
);

$server = $server->withScopeEnforcement($enforcer);
```

`Server::dispatch()` checks the operation after query validation and before the serving
hooks and the handler, and `Server::handle()` checks it once the PSR-15 adapter has built
the operation. A dispatch with no HTTP request is not checked, and neither is a request
whose grant is `null`. The grant is read once per request object.

When a cached table has no entry for an operation, the enforcer resolves it on the spot,
remembers it, and logs a warning, because the cache is out of date.

## Includes

Each hop of an explicit `?include` path needs the read scopes of the type it reaches,
intermediate hops included: `fetchCollection` for a to-many hop, `fetchOne` for a to-one
hop, every member type for a polymorphic hop. The type's own operations are not consulted,
so a type that exposes no `fetchCollection` is still checked against its
`fetchCollection` scopes. A refused hop answers `403 INSUFFICIENT_SCOPE` with
`source.parameter` `include` and the path up to that hop in `meta.include`.

A resource included by default, through `getDefaultIncludedRelationships()`, that the
caller may not read is left out of `included`. Its identifier stays in the linkage, and the
request does not fail. A hop to a type the server does not describe is checked against
the scopes the resolver gives that type by name. When the walk cannot see past a hop
because the type is not declared on a resource, it stops checking that path, logs at debug
level, and the render drops whatever the caller may not read.

## Atomic Operations

The enforcer checks the Atomic Operations endpoint's own scopes on dispatch. Each operation
in the batch is the adapter's to check, before the transaction begins:

```php
$enforcer->enforceBatch($request, [
    0 => ['articles', OperationKey::forAtomicOperation(AtomicOperationCode::Add)],
    1 => ['articles', OperationKey::forAtomicOperation(AtomicOperationCode::Update, 'tags')],
]);
```

The first entry that fails refuses the whole batch with `source.pointer`
`/atomic:operations/<index>`.

## The OpenAPI document

Server metadata implementing `OpenApi\Metadata\DeclaresOperationScopeTable` hands the
projector the same table: `scopeTable()`, the name of the security scheme the scopes are
listed under in `scopeScheme()`, and the catalogue's `scopeCatalog()`.

- Each operation needing a scope reads as needing one token that carries them. The
  requirements in its security that name the scope scheme are kept with the scopes filled
  in, and every alternative that does not name it is dropped. When none names it, the
  operation's security is `[{<scope scheme>: [scopes]}]`.
- An operation that only inherited the document default gets the result as its own
  `security`, and a `401` response when the default was empty.

If your API accepts genuinely different credential types, name the `oauth2` scheme in
your security configuration, alone or joined to the others in one requirement, so the
scoped operations keep describing the credentials they take.
- The scope scheme's flows list the catalogue's scopes after the ones its configuration
  declares. A configured description wins.
- A scheme the server does not define throws a `\LogicException`. A scheme that is not
  `oauth2` still carries the scopes, and `Projection::$scopeScheme` reports it.
- `Projection::$undeclaredScopes` lists each required scope that neither the catalogue nor
  the scheme's flows declare, with the operations needing it, so an export can fail on
  them under a strict flag.
- A table missing an entry for a projected operation throws, since it was built from other
  metadata. An empty `ScopeTable` means the server uses no scopes.

A document in which no operation needs a scope is unchanged. One that has some carries
[contract 16](openapi.md#a-bump-scoped-to-some-documents) and offers `INSUFFICIENT_SCOPE`
in its error catalogue.

## Checking without a request

`ScopeChecker` makes the same comparison outside a request, against an
[`OperationDefinition`](operation-catalog.md), so you can list the operations a token's
scopes allow.

## The error

`InsufficientScope` answers `403` with the code `INSUFFICIENT_SCOPE`. `meta.requiredScopes`
lists every scope the request needs, `meta.missingScopes` the ones the token lacks, and
`meta.include` the include path when a hop was refused. The response carries the RFC 6750
challenge:

```
WWW-Authenticate: Bearer error="insufficient_scope", scope="articles:read people:read"
```

The header comes from `CarriesResponseHeadersInterface`, which
`ErrorResponse::fromException()` and the atomic loop's error response both apply. An
adapter rendering exceptions its own way should apply it too.
