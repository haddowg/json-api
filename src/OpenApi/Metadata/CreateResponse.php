<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Marker for a success response the create operation (`POST /{type}`) may advertise.
 * The spec-valid carriers are {@see Created} (`201`), {@see NoContent} (`204` — a
 * client-generated id created with nothing to echo back) and {@see Accepted} (`202` —
 * accepted for async processing). No other response object implements this interface,
 * so an out-of-set response cannot be declared for a create.
 *
 * PHP attribute arguments forbid static method calls, so the carriers are `new`-able
 * value objects (`new Created()`, `new Accepted('jobs')`) rather than static
 * factories — legal in an `#[AsJsonApiResource(create: [...])]` argument.
 */
interface CreateResponse extends OperationResponseInterface {}
