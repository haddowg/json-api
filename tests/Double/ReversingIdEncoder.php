<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * A dependency-free reversible {@see IdEncoderInterface}: the wire id is the
 * reversed storage key. An empty wire id is undecodable (returns `null`) so the
 * 422 safety-net path is exercisable.
 */
final class ReversingIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return \strrev(\is_scalar($storageKey) ? (string) $storageKey : '');
    }

    public function decode(string $wireId): mixed
    {
        if ($wireId === '') {
            return null;
        }

        return \strrev($wireId);
    }
}
