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

    /**
     * The kind of `$operation`. A `202` job document is its write's.
     */
    public static function fromOperationType(OperationType $operation): self
    {
        return match ($operation) {
            OperationType::FetchCollection => self::FetchCollection,
            OperationType::FetchOne => self::FetchOne,
            OperationType::Create, OperationType::CreateAccepted => self::Create,
            OperationType::Update, OperationType::UpdateAccepted => self::Update,
            OperationType::Delete => self::Delete,
            OperationType::UpdateRelationship => self::UpdateRelationship,
            OperationType::AddRelationship => self::AddRelationship,
            OperationType::RemoveRelationship => self::RemoveRelationship,
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
     * The {@see OperationType} a relationship write's meta-only `200` is declared under in
     * `documentMeta()`, or `null` for any other kind.
     */
    public function relationshipDocument(): ?OperationType
    {
        return match ($this) {
            self::UpdateRelationship => OperationType::UpdateRelationship,
            self::AddRelationship => OperationType::AddRelationship,
            self::RemoveRelationship => OperationType::RemoveRelationship,
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
     * Whether an entry of an Atomic Operations batch can perform an operation of this kind
     * ({@see OperationKey::forAtomicOperation()}).
     */
    public function isAtomicWrite(): bool
    {
        return match ($this) {
            self::Create,
            self::Update,
            self::Delete,
            self::AddRelationship,
            self::UpdateRelationship,
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
