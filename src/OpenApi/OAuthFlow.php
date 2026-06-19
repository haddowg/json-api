<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 OAuth Flow Object — the configuration for a single OAuth2 flow.
 *
 * Which URL members are required depends on the flow type (the owning
 * {@see OAuthFlows} slots this under `implicit`/`password`/`clientCredentials`/
 * `authorizationCode`): `authorizationUrl` for implicit/authorization-code,
 * `tokenUrl` for password/client-credentials/authorization-code. The caller
 * supplies the members appropriate to the flow; an absent member is omitted.
 */
final readonly class OAuthFlow implements \JsonSerializable
{
    /**
     * @param array<string, string> $scopes the available scopes (name → description); always emitted (may be `{}`)
     */
    public function __construct(
        public array $scopes = [],
        public ?string $authorizationUrl = null,
        public ?string $tokenUrl = null,
        public ?string $refreshUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->authorizationUrl !== null) {
            $out['authorizationUrl'] = $this->authorizationUrl;
        }
        if ($this->tokenUrl !== null) {
            $out['tokenUrl'] = $this->tokenUrl;
        }
        if ($this->refreshUrl !== null) {
            $out['refreshUrl'] = $this->refreshUrl;
        }
        // `scopes` is a required member of every flow object, even when empty — so
        // it is always present and renders as `{}` via the object serializer.
        $out['scopes'] = $this->scopes;

        return $out;
    }

    public function toJson(): \stdClass
    {
        return Serialization::toObject($this->toArray());
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
