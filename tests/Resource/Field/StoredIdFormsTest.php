<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Field;

use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoredIdForms::class)]
final class StoredIdFormsTest extends TestCase
{
    #[Test]
    public function withoutAnEncoderBothFormsAreTheSame(): void
    {
        self::assertSame('812', StoredIdForms::wireId(null, 812));
        self::assertSame([812, '813'], StoredIdForms::forms(null, [812, '813']));
    }

    #[Test]
    public function aStorageKeyIsEncodedAndAWireIdKept(): void
    {
        $encoder = self::prefixed();

        self::assertSame('c-9', StoredIdForms::wireId($encoder, 9));
        self::assertSame('c-9', StoredIdForms::wireId($encoder, '9'));
        self::assertSame('c-9', StoredIdForms::wireId($encoder, 'c-9'));
        self::assertSame('c-9', StoredIdForms::wireId($encoder, 'C-9'));
    }

    #[Test]
    public function aBinaryKeyIsRecognisedInEitherForm(): void
    {
        $encoder = self::binary();
        $text = '2d8c3e4a-8cab-4f77-9c8a-3b4f5e6d7c81';
        $bytes = \hex2bin(\str_replace('-', '', $text));
        self::assertIsString($bytes);

        self::assertSame($text, StoredIdForms::wireId($encoder, $bytes));
        self::assertSame($text, StoredIdForms::wireId($encoder, $text));
        self::assertSame($text, StoredIdForms::wireId($encoder, \strtoupper($text)));
        self::assertSame([$bytes, $text], StoredIdForms::forms($encoder, [$bytes]));
    }

    #[Test]
    public function formsListEachKeyThenItsWireIdOnce(): void
    {
        self::assertSame([9, 'c-9', 10, 'c-10'], StoredIdForms::forms(self::prefixed(), [9, 10, '9']));
        $same = new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                return \is_scalar($storageKey) ? (string) $storageKey : '';
            }

            public function decode(string $wireId): mixed
            {
                return $wireId;
            }
        };
        self::assertSame(['a', 'b'], StoredIdForms::forms($same, ['a', 'b']));
    }

    private static function prefixed(): IdEncoderInterface
    {
        return new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                return 'c-' . (\is_scalar($storageKey) ? (string) $storageKey : '');
            }

            public function decode(string $wireId): mixed
            {
                return \preg_match('/^c-(\d+)$/i', $wireId, $m) === 1 ? (int) $m[1] : null;
            }
        };
    }

    private static function binary(): IdEncoderInterface
    {
        return new class implements IdEncoderInterface {
            public function encode(mixed $storageKey): string
            {
                if (!\is_string($storageKey) || \strlen($storageKey) !== 16) {
                    throw new \InvalidArgumentException('Not a 16-byte key.');
                }

                return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($storageKey), 4));
            }

            public function decode(string $wireId): mixed
            {
                if (\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $wireId) !== 1) {
                    return null;
                }

                return \hex2bin(\str_replace('-', '', $wireId));
            }
        };
    }
}
