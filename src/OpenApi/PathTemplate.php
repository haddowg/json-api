<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;

/**
 * The path templates the OpenAPI projection keys its `paths` by, relative to the
 * document's server URL (so a server mounted under `/api/v5` still keys `/albums/{id}`).
 *
 * The projector builds its path keys here, and {@see OperationReference} builds the
 * operation a rendered link's `describedby` points at here too, so the two can't drift.
 *
 * A singleton type's resource is addressed at `/{uriType}` with no `{id}` segment, and its
 * relationship, related and action paths hang off that, so each resource-addressed template
 * takes `$singleton`.
 */
final class PathTemplate
{
    /**
     * The placeholder the projector names a resource id path parameter.
     */
    public const ID = '{id}';

    public static function collection(string $uriType): string
    {
        return '/' . $uriType;
    }

    public static function resource(string $uriType, bool $singleton = false): string
    {
        return $singleton ? '/' . $uriType : '/' . $uriType . '/' . self::ID;
    }

    public static function related(string $uriType, string $relationship, bool $singleton = false): string
    {
        return self::resource($uriType, $singleton) . '/' . $relationship;
    }

    public static function relationship(string $uriType, string $relationship, bool $singleton = false): string
    {
        return self::resource($uriType, $singleton) . '/relationships/' . $relationship;
    }

    public static function action(string $uriType, string $action, ActionScope $scope, bool $singleton = false): string
    {
        return $scope === ActionScope::Resource
            ? self::resource($uriType, $singleton) . '/-actions/' . $action
            : self::collection($uriType) . '/-actions/' . $action;
    }
}
