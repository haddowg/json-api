<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Response;

use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Schema\Profile\AbstractProfile;
use haddowg\JsonApi\Schema\Profile\ProfileRegistry;
use haddowg\JsonApi\Tests\Double\StubResource;
use haddowg\JsonApi\Tests\Double\StubServer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
#[Group('spec:content-negotiation')]
final class ProfileApplicationTest extends TestCase
{
    public const string URI = 'https://example.com/profiles/timestamps';

    #[Test]
    public function appliesRegisteredRequestedProfileEndToEnd(): void
    {
        $server = new StubServer(profiles: new ProfileRegistry($this->timestampsProfile()));
        $request = $this->requestRequestingProfile(self::URI);

        $psr = DataResponse::fromResource(new \stdClass(), new StubResource('user', '1'))
            ->toPsrResponse($server, $request);

        $body = $this->decode((string) $psr->getBody());

        // (a) advertised on Content-Type, (c) Vary set
        self::assertStringContainsString('profile="' . self::URI . '"', $psr->getHeaderLine('Content-Type'));
        self::assertSame('Accept', $psr->getHeaderLine('Vary'));
        // (b) links.profile carries it
        self::assertIsArray($body['links']);
        self::assertSame([self::URI], $body['links']['profile']);
        // (d) finalizeDocument hook ran
        self::assertSame(['appliedBy' => 'timestamps'], $body['meta']);
    }

    #[Test]
    public function ignoresUnregisteredRequestedProfile(): void
    {
        // Empty registry: the requested profile is unrecognized → silently ignored.
        $server = new StubServer();
        $request = $this->requestRequestingProfile(self::URI);

        $psr = DataResponse::fromResource(new \stdClass(), new StubResource('user', '1'))
            ->toPsrResponse($server, $request);

        $body = $this->decode((string) $psr->getBody());

        self::assertSame('application/vnd.api+json', $psr->getHeaderLine('Content-Type'));
        self::assertSame('', $psr->getHeaderLine('Vary'));
        // No profile applied, so no links.profile — but the document still carries
        // the by-convention top-level self link.
        self::assertSame(['self' => '/users/1'], $body['links']);
    }

    private function timestampsProfile(): AbstractProfile
    {
        return new class extends AbstractProfile {
            public function uri(): string
            {
                return ProfileApplicationTest::URI;
            }

            public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
            {
                $meta = $document['meta'] ?? [];
                $document['meta'] = [...(\is_array($meta) ? $meta : []), 'appliedBy' => 'timestamps'];

                return $document;
            }
        };
    }

    private function requestRequestingProfile(string $uri): JsonApiRequest
    {
        return new JsonApiRequest(
            new ServerRequest('GET', '/users/1', ['Accept' => 'application/vnd.api+json; profile="' . $uri . '"']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
