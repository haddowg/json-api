<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;

/**
 * An in-core {@see ActionMetadataInterface} fixture — defined now so the contract is
 * exercised; this slice's component projection does not consume actions (Slice 3
 * builds the action paths).
 */
final class FakeActionMetadata implements ActionMetadataInterface
{
    /**
     * @param list<string> $methods
     * @param list<string> $tags
     */
    public function __construct(
        private readonly string $path,
        private readonly array $methods = ['POST'],
        private readonly ActionScope $scope = ActionScope::Resource,
        private readonly ActionInputMode $inputMode = ActionInputMode::None,
        private readonly ?string $inputType = null,
        private readonly ?string $outputType = null,
        private readonly bool $secured = false,
        private readonly array $tags = [],
        private readonly ?string $summary = null,
        private readonly ?string $description = null,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function methods(): array
    {
        return $this->methods;
    }

    public function scope(): ActionScope
    {
        return $this->scope;
    }

    public function inputMode(): ActionInputMode
    {
        return $this->inputMode;
    }

    public function inputType(): ?string
    {
        return $this->inputType;
    }

    public function outputType(): ?string
    {
        return $this->outputType;
    }

    public function isSecured(): bool
    {
        return $this->secured;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
