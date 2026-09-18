<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * A JSON:API exception that can describe its error code without being thrown.
 *
 * {@see JsonApiExceptionInterface::getErrors()} builds its {@see \haddowg\JsonApi\Schema\Error\Error}
 * objects at throw time from constructor arguments, so nothing can read the catalogue
 * off it — you would have to invent arguments to find out what code an exception
 * carries. A static {@see describe()} closes that gap: it answers the parts that are
 * the same for every occurrence, which is exactly the part a client can be generated
 * against.
 *
 * Every exception core ships implements this and builds its errors through
 * {@see ErrorDescriptor::toError()}, so the description and the rendered error cannot
 * disagree. It is a **separate** contract rather than a widening of
 * {@see JsonApiExceptionInterface} so an application's own exceptions keep working
 * undescribed; implement it to have a code join the projected catalogue.
 */
interface DescribedErrorInterface extends JsonApiExceptionInterface
{
    /**
     * The invariant description of this exception's error code.
     */
    public static function describe(): ErrorDescriptor;
}
