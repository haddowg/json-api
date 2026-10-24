<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

/**
 * Thrown by {@see ServeArtifactCodec::encode()} when the document holds an object outside
 * the unserialize allow-list. `$class` names it, so an adapter can report the class
 * without parsing the message.
 */
final class UnserializableValue extends \LogicException
{
    public function __construct(public readonly string $class)
    {
        parent::__construct(\sprintf(
            'The OpenAPI document holds an instance of "%s", which a serve artifact can\'t unserialize. Pass it in $allowedClasses or keep objects out of example, default and extension values.',
            $class,
        ));
    }
}
