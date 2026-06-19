<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 OAuth Flows Object — the configuration of the supported OAuth2
 * flows, each an optional {@see OAuthFlow}.
 */
final readonly class OAuthFlows implements \JsonSerializable
{
    public function __construct(
        public ?OAuthFlow $implicit = null,
        public ?OAuthFlow $password = null,
        public ?OAuthFlow $clientCredentials = null,
        public ?OAuthFlow $authorizationCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->implicit !== null) {
            $out['implicit'] = $this->implicit->toArray();
        }
        if ($this->password !== null) {
            $out['password'] = $this->password->toArray();
        }
        if ($this->clientCredentials !== null) {
            $out['clientCredentials'] = $this->clientCredentials->toArray();
        }
        if ($this->authorizationCode !== null) {
            $out['authorizationCode'] = $this->authorizationCode->toArray();
        }

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
