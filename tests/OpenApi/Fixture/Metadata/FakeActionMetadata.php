<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\AcceptedBody;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\ActionResponse;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresAcceptedBodies;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExample;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionExtensions;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionLink;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresActionSafety;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;

/**
 * An in-core {@see ActionMetadataInterface} fixture. For convenience `responds`
 * defaults from the legacy shape: an `outputType` yields a single {@see ActionResource}
 * (a `200` document), otherwise {@see NoContent} (a `204`). Pass an explicit `responds`
 * list to advertise any other response set.
 */
final class FakeActionMetadata implements DeclaresActionLink, DeclaresAcceptedBodies, DeclaresActionExample, DeclaresActionSafety, DeclaresActionExtensions
{
    /**
     * @param list<string>                  $methods
     * @param non-empty-list<ActionResponse>|null $responds
     * @param list<string>                  $tags
     * @param list<AcceptedBody>            $accepts
     * @param array<string, mixed>          $extensions
     */
    public function __construct(
        private readonly string $path,
        private readonly array $methods = ['POST'],
        private readonly ActionScope $scope = ActionScope::Resource,
        private readonly ActionInputMode $inputMode = ActionInputMode::None,
        private readonly ?string $inputType = null,
        private readonly ?string $outputType = null,
        private readonly ?array $responds = null,
        private readonly bool $secured = false,
        private readonly array $tags = [],
        private readonly ?string $summary = null,
        private readonly ?string $description = null,
        private readonly ?string $linkName = null,
        private readonly array $accepts = [],
        private readonly OperationExample|false|null $example = null,
        private readonly ?bool $readOnly = null,
        private readonly ?bool $destructive = null,
        private readonly ?bool $idempotent = null,
        private readonly array $extensions = [],
    ) {}

    public function readOnly(): ?bool
    {
        return $this->readOnly;
    }

    public function destructive(): ?bool
    {
        return $this->destructive;
    }

    public function idempotent(): ?bool
    {
        return $this->idempotent;
    }

    public function extensions(): array
    {
        return $this->extensions;
    }

    public function example(): OperationExample|false|null
    {
        return $this->example;
    }

    public function accepts(): array
    {
        return $this->accepts;
    }

    public function linkName(): ?string
    {
        return $this->linkName;
    }

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

    public function responds(): array
    {
        if ($this->responds !== null) {
            return $this->responds;
        }

        return $this->outputType !== null ? [new ActionResource($this->outputType)] : [new NoContent()];
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
