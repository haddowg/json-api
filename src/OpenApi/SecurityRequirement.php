<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

/**
 * An OpenAPI 3.1 Security Requirement Object — a map of security-scheme name to
 * the list of scopes required (an empty list for non-oauth2/oidc schemes).
 *
 * Multiple entries in one requirement are AND-ed; a document/operation `security`
 * member is a *list* of these (the alternatives are OR-ed). An empty requirement
 * (`{}` — no entries) means "no auth required" and is valid.
 *
 * Note the scope value is the one place in the OAS VO model where an **empty list**
 * is the correct emission (`"bearer": []`), so this VO serializes its own
 * `stdClass` directly rather than via the shared object-biased serializer.
 */
final readonly class SecurityRequirement implements \JsonSerializable
{
    /**
     * @param array<string, list<string>> $requirements scheme name → required scopes
     */
    public function __construct(
        public array $requirements = [],
    ) {}

    /**
     * A single-scheme requirement (the common case): scheme `$name` with optional
     * `$scopes`.
     *
     * @param list<string> $scopes
     */
    public static function scheme(string $name, array $scopes = []): self
    {
        return new self([$name => \array_values($scopes)]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->requirements as $name => $scopes) {
            $out[$name] = \array_values($scopes);
        }

        return $out;
    }

    public function toJson(): \stdClass
    {
        // Each value is a (possibly empty) list of scope strings — emit it as a JSON
        // array directly, since `"scheme": []` is the spec-correct empty form here.
        $object = new \stdClass();
        foreach ($this->toArray() as $name => $scopes) {
            $object->{$name} = $scopes;
        }

        return $object;
    }

    public function jsonSerialize(): \stdClass
    {
        return $this->toJson();
    }
}
