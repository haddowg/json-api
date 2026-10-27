<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopeTable;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresReadOnlyAttributeRejection;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\Scope\ScopeTable;

/**
 * Wraps a {@see ServerMetadataInterface} and declares whether its server rejects
 * read-only attributes in write bodies. It passes through any operation scopes the inner
 * metadata declares.
 */
final readonly class ReadOnlyAttributeRejectingServerMetadata implements DeclaresReadOnlyAttributeRejection, DeclaresOperationScopeTable
{
    public function __construct(private ServerMetadataInterface $inner, private bool $rejects = true) {}

    public function scopeTable(): ScopeTable
    {
        return $this->inner instanceof DeclaresOperationScopeTable ? $this->inner->scopeTable() : new ScopeTable();
    }

    public function scopeScheme(): string
    {
        return $this->inner instanceof DeclaresOperationScopeTable ? $this->inner->scopeScheme() : 'oauth2';
    }

    public function scopeCatalog(): array
    {
        return $this->inner instanceof DeclaresOperationScopeTable ? $this->inner->scopeCatalog() : [];
    }

    public function rejectsReadOnlyAttributes(): bool
    {
        return $this->rejects;
    }

    public function title(): string
    {
        return $this->inner->title();
    }

    public function version(): string
    {
        return $this->inner->version();
    }

    public function description(): ?string
    {
        return $this->inner->description();
    }

    public function contact(): ?Contact
    {
        return $this->inner->contact();
    }

    public function license(): ?License
    {
        return $this->inner->license();
    }

    public function servers(): array
    {
        return $this->inner->servers();
    }

    public function jsonApiVersion(): string
    {
        return $this->inner->jsonApiVersion();
    }

    public function tags(): array
    {
        return $this->inner->tags();
    }

    public function securitySchemes(): array
    {
        return $this->inner->securitySchemes();
    }

    public function defaultSecurity(): array
    {
        return $this->inner->defaultSecurity();
    }

    public function externalDocs(): ?ExternalDocumentation
    {
        return $this->inner->externalDocs();
    }

    public function profiles(): array
    {
        return $this->inner->profiles();
    }

    public function types(): array
    {
        return $this->inner->types();
    }

    public function atomicOperations(): ?AtomicOperationsMetadataInterface
    {
        return $this->inner->atomicOperations();
    }
}
