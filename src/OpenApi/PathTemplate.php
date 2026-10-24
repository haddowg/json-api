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

    public static function resource(string $uriType): string
    {
        return '/' . $uriType . '/' . self::ID;
    }

    public static function related(string $uriType, string $relationship): string
    {
        return self::resource($uriType) . '/' . $relationship;
    }

    public static function relationship(string $uriType, string $relationship): string
    {
        return self::resource($uriType) . '/relationships/' . $relationship;
    }

    public static function action(string $uriType, string $action, ActionScope $scope): string
    {
        return $scope === ActionScope::Resource
            ? self::resource($uriType) . '/-actions/' . $action
            : self::collection($uriType) . '/-actions/' . $action;
    }
}
