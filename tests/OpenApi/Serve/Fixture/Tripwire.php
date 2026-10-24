<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Serve\Fixture;

/**
 * Records whether unserializing ever constructed it.
 */
final class Tripwire implements \JsonSerializable
{
    public static bool $woken = false;

    public function __construct(public string $value = 'x') {}

    public function __wakeup(): void
    {
        self::$woken = true;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
