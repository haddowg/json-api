<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentValidation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMaxIncludeDepth;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationSecurity;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresStrictQueryParameters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Forwards the runtime facts a server declares through a decorator, answering as metadata
 * declaring nothing would when the inner server does not declare one.
 *
 * @phpstan-require-implements DeclaresOperationSecurity
 */
trait ForwardsRuntimeFacts
{
    public function canDenyAccess(?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return !$this->inner instanceof DeclaresOperationSecurity || $this->inner->canDenyAccess($type, $operation);
    }

    public function canRequireAuthentication(?TypeMetadataInterface $type, OperationKey $operation): bool
    {
        return !$this->inner instanceof DeclaresOperationSecurity || $this->inner->canRequireAuthentication($type, $operation);
    }

    public function strictQueryParameters(): bool
    {
        return !$this->inner instanceof DeclaresStrictQueryParameters || $this->inner->strictQueryParameters();
    }

    public function validatesRequestDocuments(): bool
    {
        return !$this->inner instanceof DeclaresDocumentValidation || $this->inner->validatesRequestDocuments();
    }

    public function checksResourceObjectMembers(): bool
    {
        return !$this->inner instanceof DeclaresDocumentValidation || $this->inner->checksResourceObjectMembers();
    }

    public function validatesResponseDocuments(): bool
    {
        return !$this->inner instanceof DeclaresDocumentValidation || $this->inner->validatesResponseDocuments();
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->inner instanceof DeclaresMaxIncludeDepth ? $this->inner->maxIncludeDepth() : 1;
    }
}
