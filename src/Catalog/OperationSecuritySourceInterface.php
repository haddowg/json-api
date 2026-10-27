<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;

/**
 * Tells the {@see OperationCatalog} which security rule guards each operation. Core
 * never reads a security rule, so the integration that does implements this.
 */
interface OperationSecuritySourceInterface
{
    /**
     * @param string                     $server the name of the server the operation belongs to
     * @param TypeMetadataInterface|null $type   the type the operation acts on, `null` for the Atomic Operations endpoint
     * @param OperationKey               $key    the operation, naming its type
     */
    public function securityOf(string $server, ?TypeMetadataInterface $type, OperationKey $key): OperationSecurity;
}
