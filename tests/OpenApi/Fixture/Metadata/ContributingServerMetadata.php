<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\Exception\ErrorCatalogSourceInterface;
use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ContributesErrorCodes;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * Server metadata that contributes error codes of its own — the seam wrapped round an
 * existing implementation, which is how a framework integration adds one without
 * rewriting the metadata it already builds.
 */
final readonly class ContributingServerMetadata implements ContributesErrorCodes
{
    /** @var list<ErrorCatalogSourceInterface> */
    private array $sources;

    public function __construct(
        private ServerMetadataInterface $inner,
        ErrorCatalogSourceInterface ...$sources,
    ) {
        $this->sources = \array_values($sources);
    }

    /**
     * @return list<ErrorCatalogSourceInterface>
     */
    public function errorSources(): iterable
    {
        return $this->sources;
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
