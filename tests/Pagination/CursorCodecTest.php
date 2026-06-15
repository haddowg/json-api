<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Pagination;

use haddowg\JsonApi\Exception\MalformedCursor;
use haddowg\JsonApi\Pagination\CursorBoundary;
use haddowg\JsonApi\Pagination\CursorCodec;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:pagination')]
#[Group('spec:extensions-and-profiles')]
final class CursorCodecTest extends TestCase
{
    #[Test]
    public function roundTripsABoundaryWithMixedScalarValues(): void
    {
        $codec = new CursorCodec();

        $boundary = new CursorBoundary(
            values: ['name' => 'Ada', 'rank' => 7, 'score' => 1.5, 'active' => true, 'id' => 42],
            pointsToNextItems: true,
            descending: ['name' => false, 'rank' => true, 'score' => false, 'active' => false, 'id' => false],
        );

        $token = $codec->encode($boundary);
        $decoded = $codec->decode($token, 'page[after]');

        self::assertSame($boundary->values, $decoded->values);
        self::assertTrue($decoded->pointsToNextItems);
        self::assertSame($boundary->descending, $decoded->descending);
    }

    #[Test]
    public function roundTripsThePerColumnSortDirections(): void
    {
        $codec = new CursorCodec();

        $boundary = new CursorBoundary(
            values: ['name' => 'Ada', 'id' => 7],
            pointsToNextItems: true,
            descending: ['name' => true, 'id' => false],
        );

        $decoded = $codec->decode($codec->encode($boundary), 'page[after]');

        self::assertSame(['name' => true, 'id' => false], $decoded->descending);
    }

    #[Test]
    public function roundTripsAnEmptyDirectionMap(): void
    {
        $codec = new CursorCodec();

        // The no-active-sort boundary (PK-only) still carries an explicit empty map.
        $boundary = new CursorBoundary(values: ['id' => 1], pointsToNextItems: true, descending: []);

        $decoded = $codec->decode($codec->encode($boundary), 'page[after]');

        self::assertSame([], $decoded->descending);
    }

    #[Test]
    public function roundTripsANullBoundaryValue(): void
    {
        $codec = new CursorCodec();

        // A nullable sort column legitimately carries a null boundary value.
        $boundary = new CursorBoundary(values: ['deletedAt' => null, 'id' => 5], pointsToNextItems: false, descending: ['deletedAt' => false, 'id' => false]);

        $decoded = $codec->decode($codec->encode($boundary), 'page[before]');

        self::assertNull($decoded->values['deletedAt']);
        self::assertSame(5, $decoded->values['id']);
        self::assertFalse($decoded->pointsToNextItems);
    }

    #[Test]
    public function roundTripsADateLikeStringBoundary(): void
    {
        $codec = new CursorCodec();

        // The provider stringifies dates before encoding; the codec stays scalar-only.
        $boundary = new CursorBoundary(values: ['publishedAt' => '2026-06-15T10:30:00+00:00', 'id' => 9], pointsToNextItems: true, descending: ['publishedAt' => true, 'id' => false]);

        $decoded = $codec->decode($codec->encode($boundary), 'page[after]');

        self::assertSame('2026-06-15T10:30:00+00:00', $decoded->values['publishedAt']);
    }

    #[Test]
    public function producesAUrlSafeTokenWithNoPaddingOrReservedChars(): void
    {
        $codec = new CursorCodec();

        $token = $codec->encode(new CursorBoundary(values: ['id' => 1], pointsToNextItems: true));

        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $token, 'token must be base64url with no padding');
    }

    #[Test]
    public function rejectsANonBase64Token(): void
    {
        $this->expectException(MalformedCursor::class);

        (new CursorCodec())->decode('not valid base64!!', 'page[after]');
    }

    #[Test]
    public function rejectsAValidBase64TokenThatIsNotJson(): void
    {
        $codec = new CursorCodec();
        $notJson = \rtrim(\strtr(\base64_encode('this is not json'), '+/', '-_'), '=');

        $this->expectException(MalformedCursor::class);

        $codec->decode($notJson, 'page[before]');
    }

    #[Test]
    public function rejectsAJsonTokenMissingTheDirectionFlag(): void
    {
        $codec = new CursorCodec();
        $token = $this->base64url((string) \json_encode(['id' => 1]));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function rejectsAJsonArrayRatherThanAnObject(): void
    {
        $codec = new CursorCodec();
        // A JSON list has no string keys / no direction flag — not a boundary shape.
        $token = $this->base64url((string) \json_encode([1, 2, 3]));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function rejectsANonScalarBoundaryValue(): void
    {
        $codec = new CursorCodec();
        $token = $this->base64url((string) \json_encode(['nested' => ['a' => 1], '_pointsToNextItems' => true, '_d' => []]));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function rejectsAJsonTokenMissingTheDirectionsKey(): void
    {
        $codec = new CursorCodec();
        // Carries the forward/backward flag but not the reserved direction map:
        // a directionless (pre-fix) token can no longer be decoded.
        $token = $this->base64url((string) \json_encode(['id' => 1, '_pointsToNextItems' => true]));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function rejectsADirectionsMapThatIsNotAMapOfBooleans(): void
    {
        $codec = new CursorCodec();
        $token = $this->base64url((string) \json_encode(['id' => 1, '_pointsToNextItems' => true, '_d' => ['id' => 'yes']]));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function rejectsADirectionsValueThatIsNotAMap(): void
    {
        $codec = new CursorCodec();
        $token = $this->base64url((string) \json_encode(['id' => 1, '_pointsToNextItems' => true, '_d' => 'nope']));

        $this->expectException(MalformedCursor::class);

        $codec->decode($token, 'page[after]');
    }

    #[Test]
    public function theReservedDirectionsKeyDoesNotCollideWithASortColumn(): void
    {
        $codec = new CursorCodec();

        // A JSON:API member name cannot begin with an underscore, so a column can
        // never be named `_d`; the reserved key stays distinct from boundary values.
        $boundary = new CursorBoundary(
            values: ['name' => 'Ada', 'id' => 7],
            pointsToNextItems: true,
            descending: ['name' => true, 'id' => false],
        );

        $decoded = $codec->decode($codec->encode($boundary), 'page[after]');

        self::assertArrayNotHasKey('_d', $decoded->values);
        self::assertArrayNotHasKey('_pointsToNextItems', $decoded->values);
        self::assertSame(['name' => 'Ada', 'id' => 7], $decoded->values);
        self::assertSame(['name' => true, 'id' => false], $decoded->descending);
    }

    #[Test]
    public function carriesTheParameterNameOntoTheException(): void
    {
        try {
            (new CursorCodec())->decode('not base64!!', 'page[before]');
            self::fail('expected a MalformedCursor');
        } catch (MalformedCursor $e) {
            self::assertSame('page[before]', $e->parameter);
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('page[before]', $e->getErrors()[0]->source?->parameter);
        }
    }

    private function base64url(string $json): string
    {
        return \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');
    }
}
