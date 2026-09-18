<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Exception;

/**
 * Which {@see \haddowg\JsonApi\Schema\Error\ErrorSource} member an exception always
 * populates.
 *
 * Declared on an {@see ErrorDescriptor} only when the exception sets it
 * unconditionally. An exception whose `source` is optional, conditional, or filled in
 * later by a caller (the Atomic loop decorates a registry error with the failing
 * operation's pointer) declares `null` instead — a shape that is not always there is
 * not a shape a client may rely on.
 */
enum ErrorSourceShape: string
{
    case Pointer = 'pointer';
    case Parameter = 'parameter';
    case Header = 'header';
}
