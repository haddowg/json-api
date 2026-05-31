<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Testing\Internal;

use haddowg\JsonApi\Request\JsonApiRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Produces a minimal {@see ServerRequestInterface} used by the testing
 * utilities when no originating request is supplied (rendering a response value
 * object, assembling an operation body).
 *
 * @internal
 */
final class RequestStub
{
    public static function get(): ServerRequestInterface
    {
        return new JsonApiRequest(self::psr());
    }

    /**
     * A bare PSR-7 server request for the given method.
     */
    public static function psr(string $method = 'GET'): ServerRequestInterface
    {
        // Resolved reflectively so the testing utilities do not hard-depend on a
        // specific PSR-7 implementation at the type level; nyholm/psr7 is the
        // expected provider in a test environment.
        /** @var class-string $class */
        $class = 'Nyholm\\Psr7\\ServerRequest';
        if (!\class_exists($class)) {
            throw new \RuntimeException(
                'The testing utilities require a PSR-7 implementation (install nyholm/psr7), '
                . 'or pass an explicit ServerRequestInterface.',
            );
        }

        $request = new $class($method, '/');
        if (!$request instanceof ServerRequestInterface) {
            throw new \RuntimeException('The configured PSR-7 request class is not a ServerRequestInterface.');
        }

        return $request;
    }
}
