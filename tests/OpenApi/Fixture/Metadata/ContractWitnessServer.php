<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata;

use haddowg\JsonApi\Exception\ClassListErrorSource;
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
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\Tag;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\ArrayList;
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\IntegerBuilder;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Obj;
use haddowg\JsonApi\Resource\Field\OneOf;
use haddowg\JsonApi\Resource\Field\Slug;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Field\StrBuilder;
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
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApi\Resource\Filter\WhereNotIn;
use haddowg\JsonApi\Resource\Filter\WhereNull;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\Exception\Fixture\SubscriptionExpired;
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
 * union, both enum backing types), all three client-id policies, a read-only type whose
 * relation is nonetheless mutable, a standalone type with no field inventory, an
 * unregistered linkage-only related type, every relation shape (to-one, to-many,
 * pivot-backed, polymorphic, endpoint-suppressed,
 * mutation-locked, write-only, served under a renamed URI segment), the filter/sort/pagination vocabulary (a page strategy, a cursor, a
 * menu of both, and an unpaginated collection), custom actions in each input mode and
 * scope, non-default success responses, both registered profiles, the Atomic
 * Operations extension, and described link objects (a document URL, an id example and an
 * action rendered as a resource link). Between them those reach every {@see \haddowg\JsonApi\Exception\ErrorFeature}
 * the error catalogue gates on, and a contributed error code whose descriptor carries a
 * description and an example detail.
 *
 * Widen it whenever the projector grows a branch it does not reach.
 */
final class ContractWitnessServer
{
    public static function build(): ServerMetadataInterface
    {
        $server = new FakeServerMetadata(
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

        return new LinkDeclaringServerMetadata(
            new ContributingServerMetadata($server, new ClassListErrorSource(SubscriptionExpired::class)),
            documentUrl: '/docs/openapi.json',
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
                Id::make()->example('art-1')->build(),
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
                // Constraint contexts: a create-only bound narrows only the create body, an
                // update can also write it so the read schema drops it; a field only a
                // create can write keeps its create-only bound on read; a conditional
                // `nullable()` widens every schema; an either-family IP carries no format.
                Str::make('excerpt')->onCreate(static function (StrBuilder $f): void {
                    $f->maxLength(280);
                })->build(),
                Integer::make('revision')->readOnlyOnUpdate()->onCreate(static function (IntegerBuilder $f): void {
                    $f->min(1);
                })->build(),
                Str::make('reviewNote')->when(static fn(mixed $value): bool => $value === null, static function (StrBuilder $f): void {
                    $f->nullable();
                })->build(),
                Ip::make('originIp')->build(),
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
                // Read presence inside a nested object: every child the runtime always
                // renders is required, recursively; a write-only child is not rendered and
                // a request-conditional one may be absent.
                Obj::make('contact')->nullable()->fields(
                    Str::make('firstName')->guaranteed()->build(),
                    Str::make('nickname')->build(),
                    Str::make('pin')->writeOnly()->build(),
                    Str::make('phone')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)->build(),
                    Obj::make('geo')->fields(Integer::make('lat')->build())->build(),
                )->build(),
                // Read nullability: a response promises a non-null value only when the
                // server guarantees one. An asserted read-only timestamp and a computed
                // value with a non-null return type are non-null; a plain read-only field
                // and a value flattened through a to-one are not. A sparse-by-default
                // member is left out of the read `required`.
                DateTime::make('createdAt')->readOnly()->guaranteed()->build(),
                Str::make('legacyRef')->readOnly()->build(),
                Integer::make('commentCount')->computedUsing(static fn(mixed $model): int => 0)->build(),
                Str::make('authorName')->on('author')->build(),
                Integer::make('score')->sparseByDefault()->build(),
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
                    // carries a typed `meta.pivot`, with a response shape (read-only fields
                    // in, presence and read nullability per field) and a request shape
                    // (only what a write may set, create-required fields required).
                    pivotFields: [
                        Integer::make('position')->required()->build(),
                        Boolean::make('primary')->build(),
                        DateTime::make('taggedAt')->readOnly()->guaranteed()->build(),
                        Str::make('taggedBy')->readOnly()->build(),
                        Str::make('reason')->writeOnly()->build(),
                    ],
                ),
                // Served under a URI segment other than its name: its paths and links use
                // the segment, its member and operation ids the name.
                new UriFieldNameRelationMetadata(new FakeRelationMetadata('publisher', ['people'], false), 'published-by'),
                // Relationship-only: no related endpoint, so no related document envelope.
                new FakeRelationMetadata('cover', ['images'], false, relatedEndpoint: false),
                // Mutation-locked: read-only linkage, omitted from the update request.
                new FakeRelationMetadata('locked', ['people'], true, allowsReplace: false, allowsAdd: false, allowsRemove: false),
                // Polymorphic to-many with its related endpoint exposed — the one shape
                // that needs a per-relation related collection.
                new FakeRelationMetadata('attachments', ['images', 'videos'], true),
                // Write-only, as metadata that opts into DeclaresWriteOnlyRelation reports
                // it: settable on a write, absent from every read, mutated with a `204`.
                // The fixture states the facts a write-only core relation reports too
                // (not includable, no related endpoint, not countable).
                new WriteOnlyRelationMetadata(new FakeRelationMetadata('reviewer', ['people'], false, includable: false, relatedEndpoint: false)),
                new WriteOnlyRelationMetadata(new FakeRelationMetadata('watchers', ['people'], true, includable: false, relatedEndpoint: false)),
                // Opted in but readable: projects exactly as a relation that never opted in.
                new WriteOnlyRelationMetadata(new FakeRelationMetadata('editor', ['people'], false), writeOnly: false),
            ],
            tags: ['Articles'],
            description: 'A blog article.',
            securedOperations: [OperationType::Create, OperationType::Update],
            countable: true,
            filters: [
                Where::make('status')->describedAs('Filter by status.')->example('published')->build(),
                Where::make('wordCount')->integer()->build(),
                Where::make('archived')->fixed(true)->build(),
                Range::make('rating')->example(['min' => 1, 'max' => 5])->build(),
                DateRange::make('publishedAt')->build(),
                new CommaListFilter('labels'),
                WhereAny::make('search', Contains::make('title'), Contains::make('summary'))
                    ->describedAs('Search title or summary.')->build(),
                WhereAll::make('urgent', GreaterThan::make('wordCount')->fixed(1000), BooleanFilter::make('featured')->fixed(true))->build(),
                // The value-schema fallback (ADR 0138), one branch each: a boolean column,
                // a temporal column (a string, and NOT the field's `date-time` format), a
                // set over a list column (a list of what the projector will not guess), a
                // relationship path it cannot resolve at all, and a presence-only filter
                // whose value is read by nobody.
                Where::make('featured')->build(),
                Where::make('releasedOn')->build(),
                WhereIn::make('keywords')->example(['php', 'json'])->build(),
                WhereThrough::make('author.name')->build(),
                WhereNull::make('unpublished', 'publishedAt'),
            ],
            sorts: [SortByField::make('title'), SortByField::make('wordCount')],
            actions: [
                new FakeActionMetadata('publish', ['POST'], ActionScope::Resource, ActionInputMode::None, outputType: 'articles', secured: true, tags: ['Articles'], summary: 'Publish the article', linkName: 'publish'),
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
     * A client id is permitted but optional, and `companies` is a **linkage-only** related
     * type: named by a relation that suppresses its related endpoint, and never registered,
     * so the document gives it a bare `ResourceIdentifier` and no resource object. Its
     * collection offers a pagination **menu** — the one page shape that projects as a
     * `oneOf` of strategies and makes `page[kind]` selectable (and therefore refusable).
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
            relations: [new FakeRelationMetadata('company', ['companies'], false, relatedEndpoint: false)],
            tags: ['People'],
            allowsClientId: true,
            pageSchema: (new MultiPaginator(PagePaginator::make(), CursorPaginator::make()))->describePageSchema(),
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
            filters: [
                Where::make('color')->build(),
                WhereIn::make('label')->build(),
                // A delimiter OAS cannot spell: the value documents as the one string
                // the client sends, never as a list split on the wrong character.
                WhereIdNotIn::make('excludeId')->delimiter(';')->build(),
            ],
            sorts: [SortByField::make('id')],
            responses: [OperationType::Create->value => [new NoContent()]],
        );
    }

    /**
     * Read-only: the operation allow-list exposes no write, so no create/update
     * components (standalone or atomic) are emitted for it. Its `clips` relation leaves
     * every mutation flag on, which is the branch that proves the relation flags alone do
     * not open a relationship mutation — the type has no `Update` for one to ride on, so
     * `/images/{id}/relationships/clips` is `GET`-only.
     */
    private static function images(): FakeTypeMetadata
    {
        return FakeTypeMetadata::resource(
            type: 'images',
            fields: [Id::make()->build(), Url::make('url')->build()],
            relations: [FakeRelationMetadata::toMany('clips', ['videos'])],
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
            filters: [
                WhereNotIn::make('seconds')->delimiter('|')->build(),
                WhereIdIn::make()->build(),
            ],
            tags: ['Media'],
            unpaginated: true,
            responses: [OperationType::Update->value => [new NoContent()]],
        );
    }
}
