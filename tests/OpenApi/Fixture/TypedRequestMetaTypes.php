<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\Resource\ActionRequestMetaContext;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\MetaContext;
use haddowg\JsonApi\Resource\RequestMetaContext;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;

/**
 * Types whose writes declare their request `meta`, whose resource objects declare theirs,
 * and whose relationship writes and `202`s declare their documents' `meta`, for the
 * projection tests and the contract witness.
 */
final class TypedRequestMetaTypes
{
    /**
     * A type declaring every kind of meta, in each direction and for each write.
     */
    public static function bookings(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'bookings',
            fields: [Id::make()->example('bk-1')->build(), Str::make('title')->example('Weekend')->build()],
            relations: [
                new FakeRelationMetadata(
                    'rooms',
                    ['rooms'],
                    true,
                    pivotFields: [Integer::make('nights')->example(2)->build()],
                    identifierMetaFields: [Str::make('view')->example('sea')->build()],
                    pivotAdditional: AdditionalProperties::make()->of(Str::make('*'))->build(),
                    identifierMetaAdditional: AdditionalProperties::make()->build(),
                ),
                new FakeRelationMetadata('guest', ['rooms'], false),
            ],
            actions: [
                new FakeActionMetadata(
                    'confirm',
                    methods: ['POST', 'PATCH'],
                    scope: ActionScope::Resource,
                    inputMode: ActionInputMode::None,
                    responds: [new NoContent(), new Accepted('jobs', [Integer::make('queuePosition')->example(4)])],
                    requestMeta: static fn(ActionRequestMetaContext $context): array => $context->is('POST')
                        ? [Boolean::make('silent')->example(true)]
                        : [],
                ),
                new FakeActionMetadata(
                    'quote',
                    scope: ActionScope::Collection,
                    inputMode: ActionInputMode::Document,
                    inputType: 'bookings',
                    requestMeta: static fn(): array => [Str::make('currency')->required()->example('EUR')],
                ),
            ],
            responses: [OperationType::Create->value => [new Created(), new Accepted('jobs')]],
            requestMeta: static fn(RequestMetaContext $context): array => match (true) {
                $context->is(OperationType::Create) => [Str::make('idempotencyKey')->required()->example('k-1')],
                $context->is(OperationType::Update) => [Str::make('reason')->example('late')],
                $context->is(OperationType::Delete) => [Str::make('cancellationReason')->required()->example('ill')],
                $context->isRelationship('rooms') => [Str::make('changedBy')->example('desk'), AdditionalProperties::make()],
                default => [],
            },
            resourceMeta: static fn(MetaContext $context): array => [
                Integer::make('nights')->readOnly()->example(3),
                DateTime::make('heldUntil')->readOnly(),
                Str::make('source')->writeOnly()->example('web'),
                AdditionalProperties::make()->of(Str::make('*')),
            ],
            documentMeta: [
                OperationType::AddRelationship->value => [Integer::make('moved')->required()->example(1)],
                OperationType::UpdateRelationship->value . ':guest' => [Str::make('swappedBy')->example('desk')],
                OperationType::CreateAccepted->value => [Integer::make('queuePosition')->example(7)],
            ],
        );
    }

    /**
     * The related type, declaring nothing, or declaring its read resource meta, which as a
     * pivot target also offers its link's `pivot`.
     */
    public static function rooms(bool $declaresResourceMeta = false): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'rooms',
            fields: [Id::make()->example('rm-1')->build()],
            resourceMeta: $declaresResourceMeta ? static fn(): array => [Integer::make('floor')->readOnly()->example(2)] : null,
        );
    }

    /**
     * The job type the `202`s answer with.
     */
    public static function jobs(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(type: 'jobs', fields: [Id::make()->example('job-1')->build()], operations: [OperationType::FetchOne]);
    }
}
