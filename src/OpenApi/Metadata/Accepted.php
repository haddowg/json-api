<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `202 Accepted` — the write was accepted for asynchronous processing; the body is the
 * `$jobType` job resource document to poll. Valid on create and update.
 */
final readonly class Accepted implements CreateResponse, UpdateResponse, ActionResponse
{
    public function __construct(
        private string $jobType,
    ) {
        if ($jobType === '') {
            throw new \InvalidArgumentException('A 202 Accepted response requires a non-empty job type.');
        }
    }

    public function status(): int
    {
        return 202;
    }

    public function jobType(): string
    {
        return $this->jobType;
    }
}
