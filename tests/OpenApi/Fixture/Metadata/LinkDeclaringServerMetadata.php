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
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * Server metadata that declares how its links render, wrapped round an existing
 * implementation. It passes through any error codes the inner metadata contributes.
 */
final readonly class LinkDeclaringServerMetadata implements DeclaresLinkObjects, ContributesErrorCodes, DeclaresExampleGeneration
{
    public function __construct(
        private ServerMetadataInterface $inner,
        private bool $linkObjects = true,
        private ?string $documentUrl = null,
    ) {}

    public function rendersLinkObjects(): bool
    {
        return $this->linkObjects;
    }

    public function documentUrl(): ?string
    {
        return $this->documentUrl;
    }

    public function generatesExamples(): bool
    {
        return !$this->inner instanceof DeclaresExampleGeneration || $this->inner->generatesExamples();
    }

    public function errorSources(): iterable
    {
        return $this->inner instanceof ContributesErrorCodes ? $this->inner->errorSources() : [];
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
