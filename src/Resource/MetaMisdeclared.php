<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A declared `meta` object that cannot be read as one: a member declared twice, a member
 * named like the `additionalProperties` entry, or more than one such entry. A wiring
 * error, raised when an adapter registers the type and when the OpenAPI projector reads
 * its metadata, never an error document.
 */
class MetaMisdeclared extends \LogicException {}
