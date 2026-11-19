<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * A document's top-level `meta` declared with a member twice, or a collection declaring a
 * member core writes into it. A wiring error, raised when an adapter registers the type and
 * when the OpenAPI projector reads its metadata, never an error document.
 */
final class DocumentMetaMisdeclared extends MetaMisdeclared {}
