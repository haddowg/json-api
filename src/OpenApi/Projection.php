<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * A projected document and what the projector could not do for it: one
 * {@see MissingExample} per value an operation's example needed and had no declared
 * example for. An export command can print them as warnings, or fail on any under a
 * strict flag.
 */
final readonly class Projection
{
    /**
     * @param list<MissingExample> $missingExamples
     */
    public function __construct(
        public OpenApi $document,
        public array $missingExamples = [],
    ) {}
}
