<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

/**
 * An int-backed enum with no description support — proves the backing type drives
 * the schema `type` and that var-names are emitted without descriptions.
 */
enum Priority: int
{
    case Low = 1;

    case High = 2;
}
