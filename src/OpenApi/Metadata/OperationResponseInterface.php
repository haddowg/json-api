<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * One success response a CRUD/read operation advertises in the projected OpenAPI
 * document — the declarative half of the async / no-content / redirect affordances.
 *
 * Concrete carriers are atomic, `new`-constructable value objects ({@see Created},
 * {@see Ok}, {@see NoContent}, {@see Accepted}, {@see SeeOther}, {@see MetaResult}),
 * each implementing the per-operation marker interfaces ({@see CreateResponse},
 * {@see UpdateResponse}, {@see DeleteResponse}, {@see FetchOneResponse},
 * {@see FetchCollectionResponse}) for the operations it is valid on — so an illegal
 * (operation, response) pair is rejected by the type system where possible and by
 * {@see OperationResponses::validate()} otherwise. `new` (not a static factory) is
 * required because these objects are declared in `#[AsJsonApiResource]` attribute
 * arguments, where static method calls are not constant expressions. The
 * {@see \haddowg\JsonApi\OpenApi\OperationProjector} reads {@see status()} (and
 * {@see jobType()} for a `202`) to build the concrete
 * {@see \haddowg\JsonApi\OpenApi\Response}.
 */
interface OperationResponseInterface
{
    /**
     * The HTTP status this response advertises (`200`, `201`, `202`, `204` or `303`).
     */
    public function status(): int;

    /**
     * For a `202 Accepted`, the JSON:API type whose document schema is the accepted
     * body (the pollable job resource); `null` for every other status.
     */
    public function jobType(): ?string;
}
