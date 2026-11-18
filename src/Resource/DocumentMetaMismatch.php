<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A rendered document whose top-level `meta` does not match its declaration: a required
 * member missing, an undeclared member, or a member of the wrong JSON type. Adapters check in
 * debug only, so a mistake fails loudly in development and costs nothing in production.
 */
final class DocumentMetaMismatch extends \LogicException {}
