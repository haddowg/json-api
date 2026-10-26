<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Every kind of operation a server can expose: the five CRUD operations, the five
 * related and relationship endpoints of a relation, a custom action, and the Atomic
 * Operations endpoint.
 *
 * The backing value is the word the operation's id and its `operationExamples` key start
 * with, so `fetchRelated.author` is a {@see FetchRelated} on the `author` relation. An
 * {@see OperationKey} pairs a kind with the relation, action and method it applies to.
 */
enum OperationKind: string
{
    /** `GET /{type}`. */
    case FetchCollection = 'fetchCollection';

    /** `GET /{type}/{id}`. */
    case FetchOne = 'fetchOne';

    /** `POST /{type}`. */
    case Create = 'create';

    /** `PATCH /{type}/{id}`. */
    case Update = 'update';

    /** `DELETE /{type}/{id}`. */
    case Delete = 'delete';

    /** `GET /{type}/{id}/{rel}`. */
    case FetchRelated = 'fetchRelated';

    /** `GET /{type}/{id}/relationships/{rel}`. */
    case FetchRelationship = 'fetchRelationship';

    /** `PATCH /{type}/{id}/relationships/{rel}`. */
    case UpdateRelationship = 'updateRelationship';

    /** `POST /{type}/{id}/relationships/{rel}`, to-many only. */
    case AddRelationship = 'addRelationship';

    /** `DELETE /{type}/{id}/relationships/{rel}`, to-many only. */
    case RemoveRelationship = 'removeRelationship';

    /** A custom action under the `-actions` segment, at its declared method. */
    case Action = 'action';

    /** `POST` to the Atomic Operations endpoint. */
    case Atomic = 'atomic';

    public static function fromOperationType(OperationType $operation): self
    {
        return match ($operation) {
            OperationType::FetchCollection => self::FetchCollection,
            OperationType::FetchOne => self::FetchOne,
            OperationType::Create => self::Create,
            OperationType::Update => self::Update,
            OperationType::Delete => self::Delete,
        };
    }

    /**
     * The CRUD operation this kind is, or `null` for any other kind.
     */
    public function operationType(): ?OperationType
    {
        return match ($this) {
            self::FetchCollection => OperationType::FetchCollection,
            self::FetchOne => OperationType::FetchOne,
            self::Create => OperationType::Create,
            self::Update => OperationType::Update,
            self::Delete => OperationType::Delete,
            default => null,
        };
    }

    /**
     * Whether the operation acts on one relation of the type, and so its key names it.
     */
    public function takesRelation(): bool
    {
        return match ($this) {
            self::FetchRelated,
            self::FetchRelationship,
            self::UpdateRelationship,
            self::AddRelationship,
            self::RemoveRelationship => true,
            default => false,
        };
    }

    /**
     * The upper-case HTTP method every operation of this kind answers to, or `null` for
     * an action, whose method is declared.
     */
    public function method(): ?string
    {
        return match ($this) {
            self::FetchCollection, self::FetchOne, self::FetchRelated, self::FetchRelationship => 'GET',
            self::Create, self::AddRelationship, self::Atomic => 'POST',
            self::Update, self::UpdateRelationship => 'PATCH',
            self::Delete, self::RemoveRelationship => 'DELETE',
            self::Action => null,
        };
    }
}
