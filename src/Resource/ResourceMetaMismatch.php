<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A rendered resource object whose `meta`, or a linkage identifier whose `meta`, does not
 * match its declaration: a required member missing, an undeclared member of a closed
 * object, or a value of the wrong JSON type. Adapters check in debug only, so a mistake
 * fails loudly in development and costs nothing in production.
 */
final class ResourceMetaMismatch extends \LogicException {}
