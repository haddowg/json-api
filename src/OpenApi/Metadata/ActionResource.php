<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `200 OK` with the document of a named JSON:API resource type — a custom action whose
 * success body is that type's resource document. Replaces the former
 * {@see ActionMetadataInterface} `Document` output mode + `outputType` pair.
 */
final readonly class ActionResource implements ActionResponse
{
    public function __construct(
        private string $type,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('An action resource response requires a non-empty type.');
        }
    }

    public function status(): int
    {
        return 200;
    }

    public function jobType(): ?string
    {
        return null;
    }

    /**
     * The JSON:API type whose document schema is the action's `200` response body.
     */
    public function bodyType(): string
    {
        return $this->type;
    }
}
