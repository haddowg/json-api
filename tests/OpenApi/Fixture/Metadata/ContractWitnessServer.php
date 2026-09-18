<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\OpenApi\Contact;
use haddowg\JsonApi\OpenApi\ExternalDocumentation;
use haddowg\JsonApi\OpenApi\License;
use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\NoContent;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\Time;
use haddowg\JsonApi\Resource\Field\Url;
use haddowg\JsonApi\Resource\Filter\Boolean as BooleanFilter;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;
use haddowg\JsonApi\Resource\Filter\GreaterThan;
use haddowg\JsonApi\Resource\Filter\Range;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereAll;
use haddowg\JsonApi\Resource\Filter\WhereAny;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\OpenApi\Fixture\CommaListFilter;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Priority;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Status;

/**
 * The server behind the **contract witness** — the one document
 * {@see \haddowg\JsonApi\Tests\OpenApi\ContractWitnessTest} projects and compares against a
 * committed artifact, so a projector change that moves the emitted structure cannot land
 * without a decision about {@see \haddowg\JsonApi\OpenApi\GeneratorContract::CONTRACT}.
 *
 * It is deliberately maximal rather than realistic: every branch it reaches is a branch a
 * change can be caught in, and a branch it misses is one that moves the document silently.
 * It covers the field vocabulary (scalars, formats, nested maps/objects, a discriminated
 * union, both enum backing types), all three client-id policies, a read-only type, a
 * standalone type with no field inventory, a related-only type reached across a relation,
 * every relation shape (to-one, to-many, pivot-backed, polymorphic, endpoint-suppressed,
 * mutation-locked), the filter/sort/pagination vocabulary, custom actions in each input
 * mode and scope, non-default success responses, both registered profiles, and the Atomic
 * Operations extension.
 *
 * Widen it whenever the projector grows a branch it does not reach.
 */
final class ContractWitnessServer
{
    public static function build(): FakeServerMetadata
    {
        return new FakeServerMetadata(
            title: 'Contract Witness API',
            version: '3.4.1',
            types: [
                self::articles(),
                self::people(),
                self::tags(),
                self::images(),
                self::videos(),
                FakeTypeMetadata::standalone('healthcheck', tags: ['Ops']),
            ],
            description: 'Every projector branch the witness can reach, in one document.',
            contact: new Contact('API Team', 'https://example.com/support', 'api@example.com'),
            license: new License('MIT', identifier: 'MIT'),
            servers: [
                new Server('https://api.example.com', 'Production'),
                new Server('https://staging.example.com', 'Staging'),
            ],
            tags: [
                new Tag('Articles', 'Blog articles'),
                new Tag('People'),
                new Tag('Tags'),
                new Tag('Media', 'Images and videos'),
                new Tag('Ops'),
            ],
            securitySchemes: [
                'bearer' => SecurityScheme::bearer('JWT'),
                'apiKey' => SecurityScheme::apiKey('X-Api-Key', 'header'),
            ],
            defaultSecurity: [SecurityRequirement::scheme('bearer')],
            externalDocs: new ExternalDocumentation('https://example.com/docs', 'The narrative guide'),
            atomicOperations: new FakeAtomicOperationsMetadata('/operations', 'Atomic Operations', [SecurityRequirement::scheme('bearer')]),
            profiles: [CountableProfile::URI, RelationshipQueriesProfile::URI],
        );
    }

    /**
     * The rich type: the whole field vocabulary, every relation shape, the filter/sort
     * vocabulary, custom actions in each scope and input mode, and a non-default success
     * response on three of the five operations.
     */
    private static function articles(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'articles',
            fields: [
                Id::make()->build(),
                Str::make('title')->required()->minLength(3)->maxLength(120)->describedAs('The article headline.')->example('Hello, world')->build(),
                Slug::make('slug')->build(),
                Str::make('status')->enum(Status::class)->describedAs('Publication status.')->build(),
                Integer::make('priority')->enum(Priority::class)->build(),
                Integer::make('wordCount')->nullable()->min(0)->multipleOf(10)->build(),
                Decimal::make('rating')->exclusiveMin(0.0)->exclusiveMax(10.0)->build(),
                Boolean::make('featured')->build(),
                Date::make('releasedOn')->build(),
                DateTime::make('publishedAt')->nullable()->build(),
                // The three temporal projections: a non-default format that is still
                // RFC 3339 keeps its keyword, one that is not loses it for a shape note,
                // and Time's own default is the second of those (`H:i:s` carries no
                // offset, so it is not an RFC 3339 full-time).
                DateTime::make('syncedAt')->format(\DateTimeInterface::RFC3339_EXTENDED)->build(),
                DateTime::make('archivedAt')->format('d/m/Y H:i')->build(),
                Time::make('embargoLifts')->build(),
                Email::make('editorEmail')->build(),
                Url::make('canonicalUrl')->build(),
                ArrayList::make('keywords')->minItems(1)->maxItems(10)->uniqueItems()->build(),
                ArrayHash::make('analytics')->nullable()->build(),
                Map::make('address')->fields(
                    Str::make('street')->required()->build(),
                    Str::make('city')->build(),
                )->build(),
                OneOf::make('block')->discriminator('kind')
                    ->variant('heading', Str::make('text')->required()->build())
                    ->variant('image', Url::make('src')->build())
                    ->build(),
            ],
            relations: [
                new FakeRelationMetadata('author', ['people'], false, 'Who wrote it.'),
                new FakeRelationMetadata(
                    'tags',
                    ['tags'],
                    true,
                    countable: true,
                    pageSchema: PagePaginator::make()->describePageSchema(),
                    filters: [Where::make('label')->describedAs('Filter tags by label.')->build()],
                    sorts: [SortByField::make('label')],
                    relatedIncludablePaths: ['articles'],
                    // A pivot-backed (belongsToMany) relation: the linkage identifier
                    // carries a typed `meta.pivot`.
                    pivotFields: [
                        Integer::make('position')->build(),
                        Boolean::make('primary')->build(),
                    ],
                ),
                // Relationship-only: no related endpoint, so no related document envelope.
                new FakeRelationMetadata('cover', ['images'], false, relatedEndpoint: false),
                // Mutation-locked: read-only linkage, omitted from the update request.
                new FakeRelationMetadata('locked', ['people'], true, allowsReplace: false, allowsAdd: false, allowsRemove: false),
                // Polymorphic to-many with its related endpoint exposed — the one shape
                // that needs a per-relation related collection.
                new FakeRelationMetadata('attachments', ['images', 'videos'], true),
            ],
            tags: ['Articles'],
            description: 'A blog article.',
            securedOperations: [OperationType::Create, OperationType::Update],
            countable: true,
            filters: [
                Where::make('status')->describedAs('Filter by status.')->build(),
                Where::make('wordCount')->integer()->build(),
                Where::make('archived')->fixed(true)->build(),
                Range::make('rating')->build(),
                DateRange::make('publishedAt')->build(),
                new CommaListFilter('labels'),
                WhereAny::make('search', Contains::make('title'), Contains::make('summary'))
                    ->describedAs('Search title or summary.')->build(),
                WhereAll::make('urgent', GreaterThan::make('wordCount')->fixed(1000), BooleanFilter::make('featured')->fixed(true))->build(),
            ],
            sorts: [SortByField::make('title'), SortByField::make('wordCount')],
            actions: [
                new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, ActionInputMode::None, outputType: 'articles', secured: true, tags: ['Articles'], summary: 'Publish the article'),
                new FakeActionMetadata('import', ['POST'], ActionScope::Collection, ActionInputMode::Raw, tags: ['Articles']),
                new FakeActionMetadata('draft', ['POST'], ActionScope::Resource, ActionInputMode::Document, inputType: 'articles', tags: ['Articles'], description: 'Save a draft revision.'),
                new FakeActionMetadata('stats', ['GET'], ActionScope::Collection, ActionInputMode::None, responds: [new MetaResult()], tags: ['Articles']),
            ],
            includablePaths: ['author', 'tags', 'author.company'],
            idPattern: 'art-[0-9]+',
            operationDescriptions: [OperationType::FetchCollection->value => 'List every article the caller may read.'],
            responses: [
                OperationType::FetchOne->value => [new Ok(), new SeeOther()],
                OperationType::Update->value => [new Ok(), new Accepted('article-jobs')],
                OperationType::Delete->value => [new NoContent(), new MetaResult()],
            ],
        );
    }

    /**
     * A client id is permitted but optional, and `company` is a **related-only** type:
     * reached across an exposed related endpoint without being registered, so the
     * projector synthesizes a permissive resource object for it.
     */
    private static function people(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'people',
            fields: [
                Id::make()->build(),
                Str::make('name')->required()->build(),
                Str::make('role')->enum(Status::class)->build(),
            ],
            relations: [new FakeRelationMetadata('company', ['companies'], false)],
            tags: ['People'],
            allowsClientId: true,
            filters: [Where::make('name')->build()],
            publicOperations: [OperationType::FetchCollection],
        );
    }

    /**
     * A client id is mandatory, and the collection is cursor-paginated (the one
     * paginator whose page schema omits a total). Its create answers `204`: the client
     * already knows the id it sent, so there is no resource document to echo — and
     * therefore nothing for `?include` / `fields[…]` to shape.
     */
    private static function tags(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'tags',
            fields: [
                Id::make()->build(),
                Str::make('label')->required()->build(),
            ],
            relations: [new FakeRelationMetadata('articles', ['articles'], true)],
            tags: ['Tags'],
            allowsClientId: true,
            requiresClientId: true,
            pageSchema: CursorPaginator::make()->describePageSchema(),
            filters: [Where::make('color')->build()],
            sorts: [SortByField::make('id')],
            responses: [OperationType::Create->value => [new NoContent()]],
        );
    }

    /**
     * Read-only: the operation allow-list exposes no write, so no create/update
     * components (standalone or atomic) are emitted for it.
     */
    private static function images(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'images',
            fields: [Id::make()->build(), Url::make('url')->build()],
            tags: ['Media'],
            operations: [OperationType::FetchCollection, OperationType::FetchOne],
        );
    }

    /**
     * Unpaginated: a collection with no `page[…]` parameters at all. Its update answers
     * `204`, the other write that returns no resource document to shape.
     */
    private static function videos(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'videos',
            fields: [Id::make()->build(), Url::make('url')->build(), Integer::make('seconds')->build()],
            tags: ['Media'],
            unpaginated: true,
            responses: [OperationType::Update->value => [new NoContent()]],
        );
    }
}
