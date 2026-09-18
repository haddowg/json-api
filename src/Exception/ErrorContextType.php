<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * The type of one {@see \haddowg\JsonApi\Schema\Error\Error::$context} value, as
 * declared on an {@see ErrorDescriptor}.
 *
 * Context is interpolation input — the values core substitutes into the `title` /
 * `detail` templates — so a type here says what an
 * {@see \haddowg\JsonApi\Schema\Error\ErrorMessageResolverInterface} implementation
 * will be handed for that placeholder. The backing value is the JSON Schema type
 * name, which is what the OpenAPI projection emits.
 */
enum ErrorContextType: string
{
    case Str = 'string';
    case Integer = 'integer';
    case Decimal = 'number';
    case Boolean = 'boolean';
}
