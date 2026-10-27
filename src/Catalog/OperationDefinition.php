<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Catalog;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationSafety;

/**
 * One operation a server exposes, as the {@see OperationCatalog} describes it: where it
 * is routed, how it is secured, which OAuth scopes it needs and how safe it is to call.
 */
final readonly class OperationDefinition
{
    /**
     * @param string               $server         the name of the server it belongs to
     * @param string|null          $type           the JSON:API type, `null` for the Atomic Operations endpoint
     * @param string               $method         the upper-case HTTP method
     * @param string               $pathTemplate   the path template, as the OpenAPI document keys it
     * @param list<string>         $tags
     * @param string|bool|null     $security       the security rule as the integration declares it
     * @param list<string>         $requiredScopes every scope the operation needs, including the read scopes of what a related read returns
     */
    public function __construct(
        public string $server,
        public OperationKey $key,
        public string $operationId,
        public ?string $type,
        public OperationKind $kind,
        public string $method,
        public string $pathTemplate,
        public ?string $relation,
        public ?string $actionPath,
        public ?ActionScope $actionScope,
        public array $tags,
        public string|bool|null $security,
        public SecurityLevel $securityLevel,
        public array $requiredScopes,
        public ?OperationSafety $safety,
    ) {}
}
