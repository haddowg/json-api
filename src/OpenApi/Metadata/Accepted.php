<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Resource\Field\FieldBuilderInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;

/**
 * `202 Accepted` — the write was accepted for asynchronous processing; the body is the
 * `$jobType` job resource document to poll. Valid on create and update.
 *
 * `$meta` declares the members of that document's top-level `meta`, as an
 * {@see ActionResource}'s does: the projection narrows the document's `meta` to them, a
 * member's `extractUsing()` hook supplies its value from the job resource, and an adapter
 * in debug mode refuses a response that renders another.
 */
final readonly class Accepted implements CreateResponse, UpdateResponse, ActionResponse
{
    /**
     * The members of the document's top-level `meta`, built.
     *
     * @var list<FieldInterface>
     */
    public array $meta;

    /**
     * @param list<FieldInterface|FieldBuilderInterface> $meta the members of the document's top-level `meta`
     */
    public function __construct(
        private string $jobType,
        array $meta = [],
    ) {
        if ($jobType === '') {
            throw new \InvalidArgumentException('A 202 Accepted response requires a non-empty job type.');
        }
        $this->meta = (new DocumentMeta($meta, owner: \sprintf('the 202 response of job type "%s"', $jobType)))->declaration();
    }

    /**
     * The same response with `$meta` as the members of its document's top-level `meta`.
     *
     * @param list<FieldInterface|FieldBuilderInterface> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->jobType, $meta);
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
