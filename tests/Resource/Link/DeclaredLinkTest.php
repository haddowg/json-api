<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\LinkObject;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclaredLink::class)]
#[CoversClass(DeclaredLinkBuilder::class)]
final class DeclaredLinkTest extends TestCase
{
    #[Test]
    public function theBuilderCarriesEveryDeclaredMember(): void
    {
        $link = DeclaredLink::make('upload')
            ->describedAs('Where to PUT the bytes.')
            ->example('https://uploads.example.com/f-1')
            ->rel('edit-media')
            ->title('Upload')
            ->type('application/octet-stream')
            ->hreflang('en')
            ->describedBy('https://docs.example.com/uploads')
            ->meta(Str::make('method'), DateTime::make('expiresAt')->build())
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => 'https://uploads.example.com/f-1')
            ->metaUsing(static fn(mixed $model, JsonApiRequestInterface $request): array => [])
            ->build();

        self::assertSame('upload', $link->name);
        self::assertSame('Where to PUT the bytes.', $link->description);
        self::assertSame('https://uploads.example.com/f-1', $link->example);
        self::assertSame(['method', 'expiresAt'], \array_map(static fn($f): string => $f->name(), $link->meta));
        self::assertSame(['rel' => 'edit-media', 'title' => 'Upload', 'type' => 'application/octet-stream', 'hreflang' => 'en'], $link->staticMembers());
        self::assertSame('https://docs.example.com/uploads', $link->describedBy);
        self::assertTrue($link->rendersAsObject());
    }

    #[Test]
    public function aLinkWithoutMetaOrStaticMembersRendersAsABareString(): void
    {
        $link = DeclaredLink::make('thumbnail')
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/thumbs/1.png')
            ->build();

        self::assertFalse($link->rendersAsObject());
        $resolved = $link->resolve(null, new StubJsonApiRequest());
        self::assertNotNull($resolved);
        self::assertNotInstanceOf(LinkObject::class, $resolved);
        self::assertSame('https://api.test/thumbs/1.png', $resolved->transform('https://api.test'));
    }

    #[Test]
    public function aNullOrEmptyHrefOmitsTheLinkWithoutAskingForItsMeta(): void
    {
        $metaCalls = 0;
        $link = DeclaredLink::make('upload')
            ->meta(Str::make('method'))
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): ?string => $model === 'locked' ? null : '')
            ->metaUsing(static function (mixed $model, JsonApiRequestInterface $request) use (&$metaCalls): array {
                ++$metaCalls;

                return ['method' => 'PUT'];
            })
            ->build();

        self::assertNull($link->resolve('locked', new StubJsonApiRequest()));
        self::assertNull($link->resolve('open', new StubJsonApiRequest()));
        self::assertSame(0, $metaCalls);
    }

    #[Test]
    public function metaMembersRenderTheirValuesTheWayTheirFieldsDo(): void
    {
        $link = DeclaredLink::make('upload')
            ->meta(
                Str::make('method'),
                DateTime::make('expiresAt'),
                ArrayHash::make('headers'),
                Str::make('note'),
            )
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => 'https://uploads.example.com/f-1')
            ->metaUsing(static fn(mixed $model, JsonApiRequestInterface $request): array => [
                'method' => 'PUT',
                'expiresAt' => new \DateTimeImmutable('2026-10-26 12:00:00', new \DateTimeZone('Europe/London')),
                'headers' => ['Content-Type' => 'image/png'],
                'undeclared' => 'dropped',
            ])
            ->build();

        $resolved = $link->resolve(null, new StubJsonApiRequest());

        self::assertInstanceOf(LinkObject::class, $resolved);
        self::assertSame(
            [
                'href' => 'https://uploads.example.com/f-1',
                'meta' => [
                    'method' => 'PUT',
                    'expiresAt' => '2026-10-26T12:00:00+00:00',
                    'headers' => ['Content-Type' => 'image/png'],
                    // A declared member the closure did not return renders null.
                    'note' => null,
                ],
            ],
            $resolved->transform(''),
        );
    }

    #[Test]
    public function theServerOutputZoneAppliesToTemporalMetaMembers(): void
    {
        $link = DeclaredLink::make('upload')
            ->meta(DateTime::make('expiresAt'))
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/u')
            ->metaUsing(static fn(mixed $model, JsonApiRequestInterface $request): array => [
                'expiresAt' => new \DateTimeImmutable('2026-10-26 12:00:00', new \DateTimeZone('Europe/Paris')),
            ])
            ->build();

        $resolved = $link->resolve(null, new StubJsonApiRequest(), 'UTC');

        self::assertNotNull($resolved);
        self::assertSame(['expiresAt' => '2026-10-26T11:00:00+00:00'], $resolved->meta);
    }

    #[Test]
    public function aHiddenMetaMemberIsLeftOutButTheLinkKeepsItsObjectForm(): void
    {
        $link = DeclaredLink::make('upload')
            ->meta(
                Str::make('token')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => $model === 'guest'),
            )
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/u')
            ->metaUsing(static fn(mixed $model, JsonApiRequestInterface $request): array => ['token' => 'abc'])
            ->build();

        self::assertSame(['href' => '/u', 'meta' => ['token' => 'abc']], $link->resolve('owner', new StubJsonApiRequest())?->transform(''));
        self::assertSame(['href' => '/u'], $link->resolve('guest', new StubJsonApiRequest())?->transform(''));
    }

    #[Test]
    public function oneClosureCanReturnTheHrefAndMetaTogether(): void
    {
        $link = DeclaredLink::make('upload')
            ->meta(Str::make('method'))
            ->linkUsing(static fn(mixed $model, JsonApiRequestInterface $request): ?array => $model === null
                ? null
                : ['href' => 'https://uploads.example.com/' . (\is_string($model) ? $model : ''), 'meta' => ['method' => 'PUT']])
            ->build();

        self::assertNull($link->resolve(null, new StubJsonApiRequest()));
        self::assertSame(
            ['href' => 'https://uploads.example.com/f-1', 'meta' => ['method' => 'PUT']],
            $link->resolve('f-1', new StubJsonApiRequest())?->transform(''),
        );

        $plain = DeclaredLink::make('download')
            ->linkUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/d')
            ->build();
        self::assertSame('/d', $plain->resolve(null, new StubJsonApiRequest())?->transform(''));
    }

    #[Test]
    public function staticMembersRenderInLinkObjectOrder(): void
    {
        $link = DeclaredLink::make('manual')
            ->describedBy('/docs/manual')
            ->hreflang('en')
            ->type('application/pdf')
            ->title('Manual')
            ->rel('help')
            ->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/manual.pdf')
            ->build();

        self::assertSame(
            [
                'href' => 'https://api.test/manual.pdf',
                'rel' => 'help',
                'title' => 'Manual',
                'type' => 'application/pdf',
                'hreflang' => 'en',
                'describedby' => 'https://api.test/docs/manual',
            ],
            $link->resolve(null, new StubJsonApiRequest())?->transform('https://api.test'),
        );
    }

    #[Test]
    public function aLinkUsingClosureReturningANonStringHrefFails(): void
    {
        $link = DeclaredLink::make('upload')
            ->linkUsing(static fn(mixed $model, JsonApiRequestInterface $request): array => ['href' => 42])
            ->build();

        $this->expectException(\UnexpectedValueException::class);
        $link->resolve(null, new StubJsonApiRequest());
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        $href = static fn(mixed $model, JsonApiRequestInterface $request): string => '/x';
        $meta = static fn(mixed $model, JsonApiRequestInterface $request): array => [];

        yield 'self' => [static fn() => DeclaredLink::make('self')->hrefUsing($href)->build(), 'cannot be named "self"'];
        yield 'no name' => [static fn() => DeclaredLink::make('')->hrefUsing($href)->build(), 'needs a name'];
        yield 'no href' => [static fn() => DeclaredLink::make('a')->build(), 'has no href'];
        yield 'both sources' => [static fn() => DeclaredLink::make('a')->hrefUsing($href)->linkUsing($href)->build(), 'one or the other'];
        yield 'meta without values' => [static fn() => DeclaredLink::make('a')->hrefUsing($href)->meta(Str::make('m'))->build(), 'nothing supplies their values'];
        yield 'relation member' => [static fn() => DeclaredLink::make('a')->hrefUsing($href)->metaUsing($meta)->meta(BelongsTo::make('owner', 'people'))->build(), 'is a relation or an id'];
        yield 'id member' => [static fn() => DeclaredLink::make('a')->hrefUsing($href)->metaUsing($meta)->meta(Id::make())->build(), 'is a relation or an id'];
        yield 'duplicate member' => [static fn() => DeclaredLink::make('a')->hrefUsing($href)->metaUsing($meta)->meta(Str::make('m'), Str::make('m'))->build(), 'twice'];
    }

    /**
     * @param \Closure(): mixed $declare
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDeclarations')]
    public function anIncompleteOrContradictoryDeclarationFailsToBuild(\Closure $declare, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $declare();
    }

    #[Test]
    public function aBareLinkIsAPlainLink(): void
    {
        $link = DeclaredLink::make('a')->hrefUsing(static fn(mixed $model, JsonApiRequestInterface $request): string => '/a')->build();

        $resolved = $link->resolve(null, new StubJsonApiRequest());
        self::assertNotNull($resolved);
        self::assertSame(Link::class, $resolved::class);
    }
}
