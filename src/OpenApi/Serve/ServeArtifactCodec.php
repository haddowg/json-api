<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Serve;

use Composer\InstalledVersions;
use haddowg\JsonApi\OpenApi;

/**
 * Turns a {@see ServeArtifact} into bytes an adapter can cache, and back.
 *
 * The bytes start with a fingerprint of the installed versions of this package and of
 * every package the adapter names. {@see decode()} returns null when the fingerprint no
 * longer matches, so an artifact left over from a deploy reads as missing instead of
 * unserializing into classes that changed shape and failing later.
 *
 * Unserializing is limited to the OpenAPI value objects, `\stdClass`, any enum the
 * artifact was written with, and the classes passed as `$allowedClasses`. A document whose
 * example, default or extension values hold any other object can't be encoded: either
 * name its class in `$allowedClasses` or serve that document without decorators.
 */
final readonly class ServeArtifactCodec
{
    public const string PACKAGE = 'haddowg/json-api';

    private const string FORMAT = 'haddowg/json-api serve artifact 1';

    private const array CLASSES = [
        ServeArtifact::class,
        PruneBaseline::class,
        OpenApi\Components::class,
        OpenApi\Contact::class,
        OpenApi\ExternalDocumentation::class,
        OpenApi\Header::class,
        OpenApi\Info::class,
        OpenApi\License::class,
        OpenApi\MediaType::class,
        OpenApi\OAuthFlow::class,
        OpenApi\OAuthFlows::class,
        OpenApi\OpenApi::class,
        OpenApi\Operation::class,
        OpenApi\Parameter::class,
        OpenApi\ParameterLocation::class,
        OpenApi\ParameterStyle::class,
        OpenApi\PathItem::class,
        OpenApi\Paths::class,
        OpenApi\Reference::class,
        OpenApi\RequestBody::class,
        OpenApi\Response::class,
        OpenApi\Responses::class,
        OpenApi\Schema::class,
        OpenApi\SecurityRequirement::class,
        OpenApi\SecurityScheme::class,
        OpenApi\SecuritySchemeType::class,
        OpenApi\Server::class,
        OpenApi\ServerVariable::class,
        OpenApi\Tag::class,
        \stdClass::class,
    ];

    /** @var list<string> */
    private array $packages;

    /** @var list<class-string> */
    private array $allowedClasses;

    /**
     * @param list<string>       $packages       Composer package names to add to the fingerprint, typically the adapter's own
     * @param list<class-string> $allowedClasses further classes a document's values may hold
     */
    public function __construct(array $packages = [], array $allowedClasses = [])
    {
        $this->packages = \array_values(\array_unique([self::PACKAGE, ...$packages]));
        $this->allowedClasses = $allowedClasses;
    }

    /**
     * @throws UnserializableValue when the document holds an object outside the allow-list
     */
    public function encode(ServeArtifact $artifact): string
    {
        return self::FORMAT . "\n"
            . $this->fingerprint() . "\n"
            . \implode(' ', $this->enumsOutsideAllowList($artifact)) . "\n"
            . \serialize($artifact);
    }

    /**
     * The artifact `$bytes` encode, or null when they were written by another version of
     * any fingerprinted package or don't unserialize to an artifact.
     */
    public function decode(string $bytes): ?ServeArtifact
    {
        $parts = \explode("\n", $bytes, 4);
        if (\count($parts) !== 4 || $parts[0] !== self::FORMAT || $parts[1] !== $this->fingerprint()) {
            return null;
        }

        $allowed = [...self::CLASSES, ...$this->allowedClasses];
        foreach (\array_filter(\explode(' ', $parts[2])) as $enum) {
            if (!\enum_exists($enum)) {
                return null;
            }
            $allowed[] = $enum;
        }

        try {
            $artifact = @\unserialize($parts[3], ['allowed_classes' => $allowed]);
        } catch (\Throwable) {
            return null;
        }

        return $artifact instanceof ServeArtifact ? $artifact : null;
    }

    /**
     * The installed version and source reference of every fingerprinted package.
     */
    public function fingerprint(): string
    {
        $fingerprint = [];
        foreach ($this->packages as $package) {
            $fingerprint[] = $package . '@' . (InstalledVersions::isInstalled($package)
                ? InstalledVersions::getVersion($package) . '#' . InstalledVersions::getReference($package)
                : '-');
        }

        return \implode(';', $fingerprint);
    }

    /**
     * Walks the artifact's object graph, returning the enums it holds beyond the
     * allow-list. Enums carry no state to inject, so they are allowed by name on decode.
     *
     * @return list<string>
     */
    private function enumsOutsideAllowList(ServeArtifact $artifact): array
    {
        $allowed = [];
        foreach ([...self::CLASSES, ...$this->allowedClasses] as $class) {
            $allowed[\strtolower($class)] = true;
        }

        $enums = [];
        $seen = [];
        $stack = [$artifact];
        while ($stack !== []) {
            $value = \array_pop($stack);
            if (\is_array($value)) {
                foreach ($value as $item) {
                    if (\is_array($item) || \is_object($item)) {
                        $stack[] = $item;
                    }
                }

                continue;
            }
            if (!\is_object($value) || isset($seen[\spl_object_id($value)])) {
                continue;
            }
            $seen[\spl_object_id($value)] = true;

            $class = $value::class;
            if (isset($allowed[\strtolower($class)])) {
                $stack[] = \get_mangled_object_vars($value);

                continue;
            }
            if ($value instanceof \UnitEnum) {
                $enums[$class] = true;

                continue;
            }

            throw new UnserializableValue($class);
        }

        return \array_keys($enums);
    }
}
