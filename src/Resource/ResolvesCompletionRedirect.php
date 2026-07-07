<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * The read-path counterpart of the asynchronous-processing seam: a resource (or
 * serializer) that fetches a *job* resource implements this so a `GET` on that job
 * can answer `303 See Other` — pointing at the produced resource — once the work is
 * complete, and a normal `200` (the job's status) while it is still running.
 *
 * An integration's fetch-one handler consults this after loading the entity: a
 * non-`null` return renders a {@see \haddowg\JsonApi\Response\SeeOtherResponse} to
 * that `Location`; `null` renders the resource as usual. Its OpenAPI counterpart is
 * {@see \haddowg\JsonApi\OpenApi\Metadata\SeeOther}, which
 * advertises the `303` on the fetch-one operation.
 */
interface ResolvesCompletionRedirect
{
    /**
     * The absolute URL to redirect to (`303`) when `$entity` represents completed
     * asynchronous work, or `null` to render the entity as a normal `200`.
     */
    public function completionLocation(object $entity): ?string;
}
