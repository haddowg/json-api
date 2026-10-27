<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Scope\ScopeTable;

/**
 * Server metadata that gives the projection the OAuth scopes each operation needs, so
 * every operation's `security` names them. An integration builds the table with
 * {@see \haddowg\JsonApi\Scope\ScopeTableBuilder}, the same one it enforces.
 *
 * A table in which no projected operation needs a scope leaves the document exactly as
 * it would be without this interface.
 */
interface DeclaresOperationScopeTable extends ServerMetadataInterface
{
    public function scopeTable(): ScopeTable;

    /**
     * The name of the security scheme, among {@see securitySchemes()}, the scopes are
     * listed under. It should be an `oauth2` scheme, whose flows then list the catalogue's
     * scopes too.
     */
    public function scopeScheme(): string;

    /**
     * Every scope the application defines, with its description, as
     * {@see \haddowg\JsonApi\Scope\ScopeCatalogInterface::all()} returns them. `[]` when the
     * application keeps no catalogue.
     *
     * @return array<string, string>
     */
    public function scopeCatalog(): array;
}
