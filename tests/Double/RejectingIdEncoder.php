<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Double;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;

/**
 * An {@see IdEncoderInterface} that decodes everything to null — a stand-in for
 * a well-formed but unrecognised wire id, exercising the 422 safety-net path on
 * create.
 */
final class RejectingIdEncoder implements IdEncoderInterface
{
    public function encode(mixed $storageKey): string
    {
        return \is_scalar($storageKey) ? (string) $storageKey : '';
    }

    public function decode(string $wireId): mixed
    {
        return null;
    }
}
