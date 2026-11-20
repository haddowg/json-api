<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\StaticAnalysis;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Serializer\DeclaresDocumentMetaInterface;

/**
 * Never executed. phpstan-hooks.neon analyses it with PHPStan's default settings, as a
 * consuming project would, so a `documentMeta()` override with or without the relation's
 * name that the declaration rejects fails the `composer phpstan` run.
 */
final class DocumentMetaOverrideTypes extends AbstractResource
{
    public static string $type = 'playlists';

    public function fields(): array
    {
        return [Id::make()];
    }

    public function documentMeta(OperationType $operation, ?string $relation = null): iterable
    {
        return match (true) {
            $operation === OperationType::UpdateRelationship && $relation === 'tracks' => [Integer::make('reordered')],
            $operation->isRelationshipWrite() => [Str::make('changedBy')],
            default => [],
        };
    }

    public static function oneArgument(): DeclaresDocumentMetaInterface
    {
        return new class implements DeclaresDocumentMetaInterface {
            public function documentMeta(OperationType $operation): iterable
            {
                return $operation === OperationType::AddRelationship ? [Integer::make('added')] : [];
            }
        };
    }
}
