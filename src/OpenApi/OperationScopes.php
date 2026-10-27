<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopeTable;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * The operation scopes a server's document carries: which scopes each projected operation
 * needs, how they join its `security`, and how the scope scheme's flows list them.
 *
 * {@see of()} returns `null` unless the server declares a scope table in which some
 * projected operation needs a scope, so a server that does not use scopes projects
 * exactly as before.
 *
 * @internal
 */
final class OperationScopes
{
    /** @var \WeakMap<ServerMetadataInterface, self|false>|null */
    private static ?\WeakMap $memo = null;

    /**
     * @param array<string, list<string>> $byOperationId operation id → required scopes, for every projected operation needing one
     */
    private function __construct(
        private readonly DeclaresOperationScopeTable $server,
        private readonly array $byOperationId,
    ) {}

    /**
     * @throws \LogicException when the table has no entry for a projected operation, or the scope scheme is not one of the server's security schemes
     */
    public static function of(ServerMetadataInterface $server): ?self
    {
        self::$memo ??= new \WeakMap();
        if (!isset(self::$memo[$server])) {
            self::$memo[$server] = self::resolve($server) ?? false;
        }

        $scopes = self::$memo[$server];

        return $scopes === false ? null : $scopes;
    }

    /**
     * The scopes one enumerated operation needs.
     *
     * @return list<string>
     */
    public function forOperation(EnumeratedOperation $operation): array
    {
        return $this->byOperationId[$operation->operationId] ?? [];
    }

    /**
     * The scopes the Atomic Operations endpoint needs.
     *
     * @return list<string>
     */
    public function atomic(): array
    {
        return $this->byOperationId[self::ATOMIC_OPERATION_ID] ?? [];
    }

    /**
     * An operation's `security` with its scopes named, so it reads as needing one token
     * that carries them. The requirements naming the scope scheme are kept with the scopes
     * filled in and every other alternative is dropped; when none names it, the security
     * is the scope scheme alone. An operation that inherited the document default gets
     * the result as its own. An operation needing no scope is left as it was.
     *
     * @param list<SecurityRequirement>|null $security the operation's own security, `null` when it inherits the default
     * @param list<string>                   $scopes
     * @param list<SecurityRequirement>      $default  the document's default security
     *
     * @return list<SecurityRequirement>|null
     */
    public function apply(?array $security, array $scopes, array $default): ?array
    {
        if ($scopes === []) {
            return $security;
        }

        $scheme = $this->server->scopeScheme();
        $scoped = [];
        foreach ($security ?? $default as $requirement) {
            if (\array_key_exists($scheme, $requirement->requirements)) {
                $scoped[] = new SecurityRequirement([...$requirement->requirements, $scheme => $scopes]);
            }
        }

        return $scoped !== [] ? $scoped : [SecurityRequirement::scheme($scheme, $scopes)];
    }

    /**
     * The server's security schemes, with the scope scheme's flows listing the catalogue's
     * scopes after the ones its configuration declares. A configured description wins.
     *
     * @return array<string, SecurityScheme>
     */
    public function securitySchemes(): array
    {
        $schemes = $this->server->securitySchemes();
        $name = $this->server->scopeScheme();
        $scheme = $schemes[$name];
        if ($scheme->type !== SecuritySchemeType::OAuth2 || $scheme->flows === null) {
            return $schemes;
        }

        $catalog = $this->server->scopeCatalog();
        $flow = static fn(?OAuthFlow $flow): ?OAuthFlow => $flow === null ? null : new OAuthFlow(
            [...$flow->scopes, ...\array_diff_key($catalog, $flow->scopes)],
            $flow->authorizationUrl,
            $flow->tokenUrl,
            $flow->refreshUrl,
        );
        $flows = $scheme->flows;

        $schemes[$name] = new SecurityScheme(
            $scheme->type,
            $scheme->description,
            $scheme->name,
            $scheme->in,
            $scheme->scheme,
            $scheme->bearerFormat,
            new OAuthFlows($flow($flows->implicit), $flow($flows->password), $flow($flows->clientCredentials), $flow($flows->authorizationCode)),
            $scheme->openIdConnectUrl,
        );

        return $schemes;
    }

    /**
     * Each required scope neither the catalogue nor the scope scheme's flows declare.
     *
     * @return list<UndeclaredScope>
     */
    public function undeclaredScopes(): array
    {
        $declared = $this->server->scopeCatalog();
        $scheme = $this->server->securitySchemes()[$this->server->scopeScheme()];
        foreach ([$scheme->flows?->implicit, $scheme->flows?->password, $scheme->flows?->clientCredentials, $scheme->flows?->authorizationCode] as $flow) {
            if ($flow !== null) {
                $declared += $flow->scopes;
            }
        }

        $undeclared = [];
        foreach ($this->byOperationId as $operationId => $scopes) {
            foreach ($scopes as $scope) {
                if (!\array_key_exists($scope, $declared)) {
                    $undeclared[$scope][] = $operationId;
                }
            }
        }

        $found = [];
        foreach ($undeclared as $scope => $operations) {
            $found[] = new UndeclaredScope((string) $scope, $operations);
        }

        return $found;
    }

    /**
     * Whether the scope scheme is something other than `oauth2`.
     */
    public function schemeDiagnostic(): ?ScopeSchemeNotOAuth2
    {
        $name = $this->server->scopeScheme();
        $type = $this->server->securitySchemes()[$name]->type;

        return $type === SecuritySchemeType::OAuth2 ? null : new ScopeSchemeNotOAuth2($name, $type);
    }

    private const ATOMIC_OPERATION_ID = 'atomic.operations';

    private static function resolve(ServerMetadataInterface $server): ?self
    {
        if (!$server instanceof DeclaresOperationScopeTable) {
            return null;
        }

        $table = $server->scopeTable();
        // An empty table is a server using no scopes, not a stale one.
        if ($table->types === [] && $table->atomic === null) {
            return null;
        }

        $byOperationId = [];
        foreach ($server->types() as $type) {
            foreach (OperationEnumerator::forType($type) as $operation) {
                $scopes = self::entry($table->for($type->type(), $operation->key->name()), $type, $operation->key->name());
                if ($scopes !== []) {
                    $byOperationId[$operation->operationId] = $scopes;
                }
            }
        }

        if ($server->atomicOperations() !== null) {
            $scopes = self::entry($table->atomic, null, 'atomic');
            if ($scopes !== []) {
                $byOperationId[self::ATOMIC_OPERATION_ID] = $scopes;
            }
        }

        if ($byOperationId === []) {
            return null;
        }

        if (!isset($server->securitySchemes()[$server->scopeScheme()])) {
            throw new \LogicException(\sprintf(
                'Operation scopes are listed under the security scheme "%s", which the server does not define. Define it, or name one of: %s.',
                $server->scopeScheme(),
                \implode(', ', \array_keys($server->securitySchemes())),
            ));
        }

        return new self($server, $byOperationId);
    }

    /**
     * @param list<string>|null $scopes
     *
     * @return list<string>
     */
    private static function entry(?array $scopes, ?TypeMetadataInterface $type, string $operation): array
    {
        return $scopes ?? throw new \LogicException(\sprintf(
            'The scope table has no entry for the operation "%s" of %s. Rebuild the table from the same metadata.',
            $operation,
            $type === null ? 'the Atomic Operations endpoint' : 'type "' . $type->type() . '"',
        ));
    }
}
