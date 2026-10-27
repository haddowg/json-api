# Operation catalogue

`Catalog\OperationCatalog` lists every operation a server exposes, the same ones in the
same order as its [OpenAPI document](openapi.md). Use it to show a client the operations its
user can call, or to check a token's scopes before calling, without performing anything.

It is a view over metadata your integration already has. Nothing is compiled and there is
no configuration switch.

```php
use haddowg\JsonApi\Catalog\OperationCatalog;

$catalog = new OperationCatalog(
    static fn(string $server): ServerMetadataInterface => $metadataFor($server),
    $securitySource,
);

foreach ($catalog->forServer('default') as $operation) {
    // $operation is an OperationDefinition
}
```

`forServer()` returns a generator. It reads the server's metadata when you start iterating
and builds each definition as you reach it.

## What a definition carries

| Property | What it holds |
|---|---|
| `server` | The server name passed to `forServer()`. |
| `key` | The [`OperationKey`](openapi.md#operation-examples), naming its type. |
| `operationId` | The id the OpenAPI document gives it. |
| `type` | The JSON:API type, or `null` for the Atomic Operations endpoint. |
| `kind`, `method`, `pathTemplate` | The `OperationKind`, the upper-case HTTP method and the document's path template. |
| `relation`, `actionPath`, `actionScope` | The relation, or the action's path and scope, when there is one. |
| `tags` | The OpenAPI tags. |
| `security`, `securityLevel` | The security rule your integration declares, and whether it needs nothing (`None`), the caller (`Type`) or the resource (`Entity`). |
| `requiredScopes` | The effective [OAuth scopes](scopes.md), including the read scopes of what a related read returns. |
| `safety` | The `OperationSafety` an action declares, `null` otherwise. |

The scopes come from the table the metadata declares through
`DeclaresOperationScopeTable`. Without one, or with an empty one, every operation needs
none. A table missing an operation throws a `\LogicException`, because it was built from
other metadata.

## What your integration supplies

Core never reads a security rule, so the integration provides two things:

- `Catalog\OperationSecuritySourceInterface::securityOf($server, $type, $key)` returns the
  rule guarding one operation as an `OperationSecurity`: the expression as declared
  (a string, `true`, `false` or `null`) and its `SecurityLevel`.
- `Catalog\SecurityEvaluatorInterface::evaluate($operation, $user)` judges a caller against
  a definition and answers `SecurityVerdict::Allowed`, `Denied` or `Depends`. `Depends`
  means the rule needs the resource, so the answer is known only when the operation runs.

## Checking scopes

`Scope\ScopeChecker::satisfies($operation, $granted)` compares a grant with a definition's
`requiredScopes` exactly as enforcement does, through the same `ScopeMatcherInterface`. A
`null` grant, a request with no scoped token, satisfies every operation.

```php
$checker = new ScopeChecker($matcher);

$callable = [];
foreach ($catalog->forServer('default') as $operation) {
    if ($checker->satisfies($operation, $granted)
        && $evaluator->evaluate($operation, $user) !== SecurityVerdict::Denied) {
        $callable[] = $operation->operationId;
    }
}
```
