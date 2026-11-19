<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

/**
 * The call a custom action's request `meta` is declared for, as its handler's
 * {@see DeclaresActionRequestMetaInterface::requestDocumentMeta()} receives it: the HTTP
 * method the action is called with, so an action mounted for several methods can declare
 * each its own members.
 *
 * It carries no request. The OpenAPI projection asks for each method's declaration with
 * none, so a declaration may depend only on what this context says.
 */
final readonly class ActionRequestMetaContext
{
    /**
     * The upper-case HTTP method.
     */
    public string $method;

    public function __construct(string $method)
    {
        $this->method = \strtoupper($method);
    }

    /**
     * Whether the action is called with `$method`, in any case.
     */
    public function is(string $method): bool
    {
        return $this->method === \strtoupper($method);
    }
}
