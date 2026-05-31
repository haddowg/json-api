<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

/**
 * A per-instance registry of the profiles a server recognizes, keyed by URI.
 *
 * A simple eager map: the spec requires no negotiation across profiles (no
 * quality factors), so lookup is an O(1) URI match. Registering the same URI
 * twice is a configuration error ({@see ProfileAlreadyRegistered}). This
 * registry is injected, never global, and is owned by the `Server`. Its public
 * API is {@see register()} / {@see has()} / {@see get()} / {@see all()}.
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 */
final class ProfileRegistry
{
    /**
     * @var array<string, ProfileInterface>
     */
    private array $profiles = [];

    public function __construct(ProfileInterface ...$profiles)
    {
        foreach ($profiles as $profile) {
            $this->register($profile);
        }
    }

    /**
     * @throws ProfileAlreadyRegistered when a profile with the same URI is already registered
     */
    public function register(ProfileInterface $profile): void
    {
        $uri = $profile->uri();
        if (isset($this->profiles[$uri])) {
            throw new ProfileAlreadyRegistered($uri);
        }

        $this->profiles[$uri] = $profile;
    }

    public function has(string $uri): bool
    {
        return isset($this->profiles[$uri]);
    }

    public function get(string $uri): ?ProfileInterface
    {
        return $this->profiles[$uri] ?? null;
    }

    /**
     * @return list<ProfileInterface>
     */
    public function all(): array
    {
        return \array_values($this->profiles);
    }
}
