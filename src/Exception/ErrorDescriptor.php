<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;

/**
 * What an exception can say about its error *before* anything goes wrong: the stable
 * `code`, the HTTP status, the default `title`, the shape of the interpolation context,
 * and the `source` member it always fills.
 *
 * The three wire members a client matches on (`code`, `status`, `title`) live here and
 * nowhere else — {@see toError()} is how the exception builds its {@see Error}, so a
 * descriptor cannot drift from what the exception actually renders. That is what makes
 * the catalogue safe to publish: {@see ErrorCatalog} reads descriptors off the classes
 * without constructing a single exception, and the OpenAPI projection turns each one
 * into a named `components.schemas.<Code>Error` variant.
 *
 * `$context` is the *shape* of {@see Error::$context} — the `{placeholder}` tokens core
 * fills into the `title` / `detail` templates for this code, which is what an
 * {@see \haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface} must support in a
 * replacement template. Context is interpolation input, resolved before the response is
 * sent and never a wire member, so this is where the placeholder shape is published: the
 * OpenAPI projection leaves it out entirely, because a template author writes PHP and a
 * generated document describes only what a client receives.
 */
final readonly class ErrorDescriptor
{
    /**
     * @param string                          $code    the stable machine-readable error code
     * @param int                             $status  the HTTP status this error responds with
     * @param string                          $title   the default (pre-localization) short title
     * @param array<string, ErrorContextType> $context the interpolation placeholders, by name
     * @param ErrorSourceShape|null           $source  the `source` member always filled, if any
     * @param ErrorFeature|null               $feature the capability the error depends on, if any
     */
    public function __construct(
        public string $code,
        public int $status,
        public string $title,
        public array $context = [],
        public ?ErrorSourceShape $source = null,
        public ?ErrorFeature $feature = null,
    ) {}

    /**
     * Builds the error object for one occurrence: the descriptor supplies `code`,
     * `status` and `title`; the caller supplies what only the throw site knows.
     *
     * @param array<string, scalar|\Stringable> $context the interpolation values, keyed as {@see $context} declares
     * @param array<string, mixed>              $meta
     */
    public function toError(string $detail, array $context = [], ?ErrorSource $source = null, array $meta = []): Error
    {
        return new Error(
            status: (string) $this->status,
            code: $this->code,
            title: $this->title,
            detail: $detail,
            source: $source,
            meta: $meta,
            context: $context,
        );
    }
}
