<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;

/**
 * An in-core {@see ServerMetadataInterface} fixture — a plain value carrier so the
 * projector tests need no Symfony.
 */
final class FakeServerMetadata implements ServerMetadataInterface
{
    /**
     * @param list<TypeMetadataInterface>      $types
     * @param list<Server>                     $servers
     * @param list<Tag>                        $tags
     * @param array<string, SecurityScheme>    $securitySchemes
     * @param list<SecurityRequirement>        $defaultSecurity
     */
    public function __construct(
        private readonly string $title,
        private readonly string $version,
        private readonly array $types,
        private readonly ?string $description = null,
        private readonly ?Contact $contact = null,
        private readonly ?License $license = null,
        private readonly array $servers = [],
        private readonly string $jsonApiVersion = '1.1',
        private readonly array $tags = [],
        private readonly array $securitySchemes = [],
        private readonly array $defaultSecurity = [],
        private readonly ?ExternalDocumentation $externalDocs = null,
        private readonly ?AtomicOperationsMetadataInterface $atomicOperations = null,
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function contact(): ?Contact
    {
        return $this->contact;
    }

    public function license(): ?License
    {
        return $this->license;
    }

    public function servers(): array
    {
        return $this->servers;
    }

    public function jsonApiVersion(): string
    {
        return $this->jsonApiVersion;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function securitySchemes(): array
    {
        return $this->securitySchemes;
    }

    public function defaultSecurity(): array
    {
        return $this->defaultSecurity;
    }

    public function externalDocs(): ?ExternalDocumentation
    {
        return $this->externalDocs;
    }

    public function types(): array
    {
        return $this->types;
    }

    public function atomicOperations(): ?AtomicOperationsMetadataInterface
    {
        return $this->atomicOperations;
    }
}
