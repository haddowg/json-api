<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionSafety;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionScopes;

/**
 * Action metadata that declares its scopes, wrapped round {@see FakeActionMetadata}.
 */
final readonly class ScopedActionMetadata implements DeclaresActionScopes, DeclaresActionSafety
{
    /**
     * @param list<string>|null $scopes
     */
    public function __construct(
        private FakeActionMetadata $inner,
        private ?array $scopes,
    ) {}

    public function scopes(): ?array
    {
        return $this->scopes;
    }

    public function readOnly(): ?bool
    {
        return $this->inner->readOnly();
    }

    public function destructive(): ?bool
    {
        return $this->inner->destructive();
    }

    public function idempotent(): ?bool
    {
        return $this->inner->idempotent();
    }

    public function path(): string
    {
        return $this->inner->path();
    }

    public function methods(): array
    {
        return $this->inner->methods();
    }

    public function scope(): ActionScope
    {
        return $this->inner->scope();
    }

    public function inputMode(): ActionInputMode
    {
        return $this->inner->inputMode();
    }

    public function inputType(): ?string
    {
        return $this->inner->inputType();
    }

    public function responds(): array
    {
        return $this->inner->responds();
    }

    public function isSecured(): bool
    {
        return $this->inner->isSecured();
    }

    public function tags(): array
    {
        return $this->inner->tags();
    }

    public function summary(): ?string
    {
        return $this->inner->summary();
    }

    public function description(): ?string
    {
        return $this->inner->description();
    }
}
