<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Examples\MusicCatalog\Profile;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Schema\Profile\AbstractProfile;

/**
 * A worked custom {@see \haddowg\JsonApi\Schema\Profile\ProfileInterface}: it
 * stamps the moment the document was generated into the top-level `meta` member.
 *
 * It extends {@see AbstractProfile} (so {@see keywords()} defaults to none) and
 * overrides only {@see finalizeDocument()} — the hook the response layer runs once,
 * after the body array is assembled and before it is encoded, for every applied
 * profile. Registering it via {@see \haddowg\JsonApi\Server\Server::withProfile()}
 * makes the server recognise its {@see uri()}, so a request asking for it (via the
 * `Accept` `profile` parameter or the `profile` query parameter) has the timestamp
 * stamped and the URI echoed in `links.profile` + the response `Content-Type`.
 *
 * The clock is injectable so a test can freeze it and assert a deterministic
 * `meta.generatedAt`.
 */
final class TimestampProfile extends AbstractProfile
{
    public const URI = 'https://music.example/profiles/timestamp';

    /**
     * @var \Closure(): \DateTimeImmutable
     */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock the time source; defaults to "now"
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public static function frozenAt(\DateTimeImmutable $instant): self
    {
        return new self(static fn(): \DateTimeImmutable => $instant);
    }

    public function uri(): string
    {
        return self::URI;
    }

    /**
     * @return list<string>
     */
    public function keywords(): array
    {
        return ['generatedAt'];
    }

    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array
    {
        $meta = $document['meta'] ?? [];
        if (!\is_array($meta)) {
            $meta = [];
        }

        $meta['generatedAt'] = ($this->clock)()->format(\DateTimeInterface::ATOM);
        $document['meta'] = $meta;

        return $document;
    }
}
