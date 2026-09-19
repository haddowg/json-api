<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * A contributor of described error classes to an {@see ErrorCatalog}.
 *
 * Core ships {@see CoreErrorSource} for its own exceptions and {@see ClassListErrorSource}
 * for a fixed list. Implement this to contribute your own — a framework integration hands
 * the catalogue whatever its own discovery already found, and an application with no
 * discovery machinery names its classes directly.
 *
 * A source yields **classes**, never descriptors: the class is the single place a code,
 * status and title are written, and {@see DescribedErrorInterface::describe()} reads them
 * off it without constructing anything.
 */
interface ErrorCatalogSourceInterface
{
    /**
     * The described-error classes this source contributes, in the order it wants them
     * considered.
     *
     * @return iterable<class-string<DescribedErrorInterface>>
     */
    public function describedErrors(): iterable;
}
