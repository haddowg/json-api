<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Server;

use haddowg\JsonApi\Server\RequestBaseUri;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The base URI every generated link is prefixed with resolves from the request
 * origin when no fixed base is configured, and from the configured base
 * (trailing-slash tolerant) when one is.
 */
#[Group('spec:document-links')]
final class RequestBaseUriTest extends TestCase
{
    #[Test]
    public function anEmptyConfiguredBaseResolvesToTheRequestOrigin(): void
    {
        self::assertSame(
            'https://music.example',
            RequestBaseUri::resolve('', new Uri('https://music.example/albums/1?foo=bar')),
        );
    }

    #[Test]
    public function theOriginCarriesTheSchemePortAndUserinfoOfTheRequest(): void
    {
        self::assertSame(
            'http://user:pass@music.example:8080',
            RequestBaseUri::resolve('', new Uri('http://user:pass@music.example:8080/albums')),
        );
    }

    #[Test]
    public function aConfiguredBaseIsUsedVerbatimRegardlessOfTheRequestHost(): void
    {
        self::assertSame(
            'https://canonical.example',
            RequestBaseUri::resolve('https://canonical.example', new Uri('https://music.example/albums/1')),
        );
    }

    #[Test]
    #[DataProvider('trailingSlashCases')]
    public function aConfiguredBaseIsTrimmedOfTrailingSlashes(string $configured, string $expected): void
    {
        self::assertSame($expected, RequestBaseUri::resolve($configured, new Uri('https://music.example/albums')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function trailingSlashCases(): iterable
    {
        yield 'absolute base with a trailing slash' => ['https://canonical.example/', 'https://canonical.example'];
        yield 'absolute base with a path and trailing slash' => ['https://canonical.example/api/', 'https://canonical.example/api'];
        yield 'path-only base with a trailing slash' => ['/api/', '/api'];
        yield 'multiple trailing slashes are all trimmed' => ['https://canonical.example///', 'https://canonical.example'];
        yield 'no trailing slash is left untouched' => ['https://canonical.example/api', 'https://canonical.example/api'];
    }

    #[Test]
    public function aRequestWithoutAnAuthorityDegradesToHostRelative(): void
    {
        // A relative / path-only request URI has no authority to derive an origin
        // from — the base falls back to '' so the link stays host-relative rather
        // than becoming a broken `://path` prefix.
        self::assertSame('', RequestBaseUri::resolve('', new Uri('/albums/1')));
    }

    #[Test]
    public function aRequestWithAnAuthorityButNoSchemeDegradesToHostRelative(): void
    {
        // A scheme-relative `//host/path` URI carries an authority but no scheme to
        // pair it with; there is no well-formed origin, so it degrades gracefully.
        self::assertSame('', RequestBaseUri::resolve('', new Uri('//music.example/albums')));
    }
}
