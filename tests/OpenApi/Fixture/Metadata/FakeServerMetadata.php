<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentValidation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresExampleGeneration;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMaxIncludeDepth;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationSecurity;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresStrictQueryParameters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;

/**
 * An in-core {@see ServerMetadataInterface} fixture — a plain value carrier so the
 * projector tests need no Symfony.
 *
 * It reports the runtime facts an adapter may declare, defaulting each to the answer that
 * offers the codes it decides as metadata declaring nothing would: every operation can deny
 * access and require authentication, query parameters are strict, every document is
 * validated, and a server maximum include depth is set.
 */
final class FakeServerMetadata implements ServerMetadataInterface, DeclaresExampleGeneration, DeclaresOperationSecurity, DeclaresStrictQueryParameters, DeclaresDocumentValidation, DeclaresMaxIncludeDepth
{
    /**
     * @param list<TypeMetadataInterface>      $types
     * @param list<Server>                     $servers
     * @param list<Tag>                        $tags
     * @param array<string, SecurityScheme>    $securitySchemes
     * @param list<SecurityRequirement>        $defaultSecurity
     * @param list<string>                     $profiles
     * @param (\Closure(?TypeMetadataInterface, OperationKey): bool)|null $canDenyAccess            `null` for every operation
     * @param (\Closure(?TypeMetadataInterface, OperationKey): bool)|null $canRequireAuthentication `null` for every operation
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
        private readonly array $profiles = [],
        private readonly bool $generatesExamples = true,
        private readonly ?\Closure $canDenyAccess = null,
        private readonly ?\Closure $canRequireAuthentication = null,
        private readonly bool $strictQueryParameters = true,
        private readonly bool $validatesRequestDocuments = true,
        private readonly bool $checksResourceObjectMembers = true,
        private readonly bool $validatesResponseDocuments = true,
        private readonly ?int $maxIncludeDepth = 1,
    ) {}

    public function canDenyAccess(?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return $this->canDenyAccess === null || ($this->canDenyAccess)($type, $operation) === true;
    }

    public function canRequireAuthentication(?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return $this->canRequireAuthentication === null || ($this->canRequireAuthentication)($type, $operation) === true;
    }

    public function strictQueryParameters(): bool
    {
        return $this->strictQueryParameters;
    }

    public function validatesRequestDocuments(): bool
    {
        return $this->validatesRequestDocuments;
    }

    public function checksResourceObjectMembers(): bool
    {
        return $this->checksResourceObjectMembers;
    }

    public function validatesResponseDocuments(): bool
    {
        return $this->validatesResponseDocuments;
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->maxIncludeDepth;
    }

    public function generatesExamples(): bool
    {
        return $this->generatesExamples;
    }

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

    public function profiles(): array
    {
        return $this->profiles;
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
