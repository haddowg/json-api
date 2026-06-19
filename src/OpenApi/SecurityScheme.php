<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Security Scheme Object — a single authentication mechanism the
 * API supports, landing under `components.securitySchemes`.
 *
 * The valid members depend on `type` (the OAS meta-schema enforces this via
 * conditional sub-schemas): `apiKey` needs `name`+`in`; `http` needs `scheme`
 * (and may carry `bearerFormat` when `scheme` is `bearer`); `oauth2` needs
 * `flows`; `openIdConnect` needs `openIdConnectUrl`. The named factories build a
 * scheme with only the members its type permits, so a constructed scheme always
 * meta-validates.
 */
final readonly class SecurityScheme implements \JsonSerializable
{
    public function __construct(
        public SecuritySchemeType $type,
        public ?string $description = null,
        public ?string $name = null,
        public ?string $in = null,
        public ?string $scheme = null,
        public ?string $bearerFormat = null,
        public ?OAuthFlows $flows = null,
        public ?string $openIdConnectUrl = null,
    ) {}

    /**
     * An `apiKey` scheme: a key carried in `$in` (`query`/`header`/`cookie`) under
     * the member name `$name`.
     */
    public static function apiKey(string $name, string $in, ?string $description = null): self
    {
        return new self(SecuritySchemeType::ApiKey, $description, name: $name, in: $in);
    }

    /**
     * An `http` scheme using the given HTTP auth `$scheme` (e.g. `basic`), with an
     * optional `$bearerFormat` hint (only meaningful for `bearer`).
     */
    public static function http(string $scheme, ?string $bearerFormat = null, ?string $description = null): self
    {
        return new self(SecuritySchemeType::Http, $description, scheme: $scheme, bearerFormat: $bearerFormat);
    }

    /**
     * The conventional `http`/`bearer` scheme, optionally hinting the token format
     * (e.g. `JWT`).
     */
    public static function bearer(?string $bearerFormat = null, ?string $description = null): self
    {
        return self::http('bearer', $bearerFormat, $description);
    }

    /**
     * An `oauth2` scheme described by its supported `$flows`.
     */
    public static function oauth2(OAuthFlows $flows, ?string $description = null): self
    {
        return new self(SecuritySchemeType::OAuth2, $description, flows: $flows);
    }

    /**
     * An `openIdConnect` scheme discovered at `$openIdConnectUrl`.
     */
    public static function openIdConnect(string $openIdConnectUrl, ?string $description = null): self
    {
        return new self(SecuritySchemeType::OpenIdConnect, $description, openIdConnectUrl: $openIdConnectUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type->value];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->in !== null) {
            $out['in'] = $this->in;
        }
        if ($this->scheme !== null) {
            $out['scheme'] = $this->scheme;
        }
        if ($this->bearerFormat !== null) {
            $out['bearerFormat'] = $this->bearerFormat;
        }
        if ($this->flows !== null) {
            $out['flows'] = $this->flows->toArray();
        }
        if ($this->openIdConnectUrl !== null) {
            $out['openIdConnectUrl'] = $this->openIdConnectUrl;
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
