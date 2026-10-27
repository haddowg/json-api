<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ContributesErrorCodes;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresExampleGeneration;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkObjects;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationScopeTable;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresReadOnlyAttributeRejection;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Scope\ScopeTable;

/**
 * Server metadata that declares its operation scopes, wrapped round an existing
 * implementation. It adds the scope scheme to the inner security schemes and passes
 * every other opt-in the inner metadata declares through unchanged.
 */
final readonly class ScopeDeclaringServerMetadata implements DeclaresOperationScopeTable, DeclaresLinkObjects, ContributesErrorCodes, DeclaresExampleGeneration, DeclaresReadOnlyAttributeRejection
{
    /**
     * @param array<string, SecurityScheme> $schemes extra security schemes, such as the scope scheme
     * @param array<string, string>         $catalog
     */
    public function __construct(
        private ServerMetadataInterface $inner,
        private ScopeTable $table,
        private string $scheme = 'oauth2',
        private array $schemes = [],
        private array $catalog = [],
    ) {}

    public function scopeTable(): ScopeTable
    {
        return $this->table;
    }

    public function scopeScheme(): string
    {
        return $this->scheme;
    }

    public function scopeCatalog(): array
    {
        return $this->catalog;
    }

    public function rendersLinkObjects(): bool
    {
        return !$this->inner instanceof DeclaresLinkObjects || $this->inner->rendersLinkObjects();
    }

    public function documentUrl(): ?string
    {
        return $this->inner instanceof DeclaresLinkObjects ? $this->inner->documentUrl() : null;
    }

    public function errorSources(): iterable
    {
        return $this->inner instanceof ContributesErrorCodes ? $this->inner->errorSources() : [];
    }

    public function generatesExamples(): bool
    {
        return !$this->inner instanceof DeclaresExampleGeneration || $this->inner->generatesExamples();
    }

    public function rejectsReadOnlyAttributes(): bool
    {
        return $this->inner instanceof DeclaresReadOnlyAttributeRejection && $this->inner->rejectsReadOnlyAttributes();
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
        return [...$this->inner->securitySchemes(), ...$this->schemes];
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
