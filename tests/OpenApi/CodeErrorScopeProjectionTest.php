<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\OpenApi;

use haddowg\JsonApi\Exception\CoreErrorSource;
use haddowg\JsonApi\Exception\ErrorCatalog;
use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApi\OpenApi\ErrorCoverage;
use haddowg\JsonApi\OpenApi\Metadata\ActionInputMode;
use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\OpenApi\OAuthFlow;
use haddowg\JsonApi\OpenApi\OAuthFlows;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationFacts;
use haddowg\JsonApi\OpenApi\SecurityScheme;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApi\Tests\Double\ReversingIdEncoder;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeActionMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeAtomicOperationsMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeRelationMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\FakeTypeMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ReadOnlyAttributeRejectingServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeDeclaringServerMetadata;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\ScopeTables;
use haddowg\JsonApi\Tests\OpenApi\Fixture\Metadata\WriteOnlyRelationMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each of core's codes is offered on the operations whose runtime can raise it, narrowed by
 * what the type, relation or action declares, one representative case per rule.
 */
#[CoversClass(ErrorCoverage::class)]
#[CoversClass(ErrorCatalogProjector::class)]
#[CoversClass(OperationFacts::class)]
final class CodeErrorScopeProjectionTest extends TestCase
{
    #[Test]
    public function everyCoreCodeFollowsARule(): void
    {
        foreach ((new CoreErrorSource())->describedErrors() as $class) {
            self::assertArrayHasKey($class, ErrorCoverage::RULES, $class . ' names no coverage rule.');
        }
    }

    #[Test]
    public function aRefusedClientIdIsOfferedOnlyOnTheCreatesOfATypeThatRefusesOne(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]],
            comments: ['allowsClientId' => true],
        ));

        self::assertContains('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->codes($document, '/articles', 'post'));
        self::assertNotContains('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->codes($document, '/comments', 'post'), 'the type takes a client id');
        self::assertNotContains('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->codes($document, '/articles/{id}', 'patch'), 'an update never hydrates an id');
        foreach (['patch', 'post', 'delete'] as $method) {
            self::assertNotContains('CLIENT_GENERATED_ID_NOT_SUPPORTED', $this->codes($document, '/articles/{id}/relationships/comments', $method), "a relationship {$method} carries no resource id");
        }
    }

    #[Test]
    public function theQueryParameterCodesStayOffTheOperationsThatIgnoreTheirParameter(): void
    {
        $document = $this->project($this->server(
            articles: ['filters' => [Where::make('title')->build()], 'sorts' => [SortByField::make('title')]],
        ));

        foreach (['SORTING_UNRECOGNIZED', 'FILTERING_UNRECOGNIZED', 'FILTER_VALUE_INVALID'] as $code) {
            self::assertContains($code, $this->codes($document, '/articles', 'get'), $code);
            foreach ([['/articles/{id}', 'get'], ['/articles/{id}', 'delete'], ['/articles', 'post'], ['/articles/{id}', 'patch']] as [$path, $method]) {
                self::assertNotContains($code, $this->codes($document, $path, $method), "{$code} on {$method} {$path}");
            }
        }
        foreach (['INCLUSION_UNRECOGNIZED', 'INCLUSION_NOT_ALLOWED', 'INCLUSION_DEPTH_EXCEEDED'] as $code) {
            self::assertContains($code, $this->codes($document, '/articles/{id}', 'get'), $code);
            self::assertNotContains($code, $this->codes($document, '/articles/{id}', 'delete'), $code);
        }
        // Every operation still checks its parameter names and their shape.
        self::assertContains('QUERY_PARAM_UNRECOGNIZED', $this->codes($document, '/articles/{id}', 'delete'));
        self::assertContains('QUERY_PARAM_MALFORMED', $this->codes($document, '/articles/{id}', 'delete'));
    }

    #[Test]
    public function aSortIsUnsupportedWhereNothingSortsAndUnrecognizedWhereSomethingDoes(): void
    {
        $document = $this->project($this->server(articles: ['sorts' => [SortByField::make('title')]]));

        self::assertContains('SORTING_UNRECOGNIZED', $this->codes($document, '/articles', 'get'));
        self::assertNotContains('SORTING_UNSUPPORTED', $this->codes($document, '/articles', 'get'));
        self::assertContains('SORTING_UNSUPPORTED', $this->codes($document, '/comments', 'get'));
        self::assertNotContains('SORTING_UNRECOGNIZED', $this->codes($document, '/comments', 'get'));
        self::assertNotContains('FILTER_VALUE_INVALID', $this->codes($document, '/comments', 'get'), 'nothing declares a filter');
        self::assertContains('FILTERING_UNRECOGNIZED', $this->codes($document, '/comments', 'get'), 'any filter key is unknown');
    }

    #[Test]
    public function theRelationshipQueriesProfileOpensTheSortAndFilterCodesToTheReadsItReaches(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]],
            comments: ['filters' => [Where::make('body')->build()], 'sorts' => [SortByField::make('body')]],
            profiles: [RelationshipQueriesProfile::URI],
        ));

        foreach (['SORTING_UNRECOGNIZED', 'FILTERING_UNRECOGNIZED', 'FILTER_VALUE_INVALID'] as $code) {
            self::assertContains($code, $this->codes($document, '/articles/{id}', 'get'), $code);
            self::assertContains($code, $this->codes($document, '/articles', 'post'), $code);
            self::assertContains($code, $this->codes($document, '/articles/{id}', 'patch'), $code);
            self::assertNotContains($code, $this->codes($document, '/comments/{id}', 'get'), 'a comment has no relation to query');
            self::assertNotContains($code, $this->codes($document, '/articles/{id}', 'delete'), $code);
        }
        self::assertNotContains('SORTING_UNSUPPORTED', $this->codes($document, '/articles/{id}', 'get'), 'comments sort by something');
    }

    #[Test]
    public function aRelationshipQueryOverNoVocabularyRefusesAnySortAndAnyFilterKey(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]],
            profiles: [RelationshipQueriesProfile::URI],
        ));

        $codes = $this->codes($document, '/articles/{id}', 'get');
        self::assertContains('SORTING_UNSUPPORTED', $codes);
        self::assertContains('FILTERING_UNRECOGNIZED', $codes);
        self::assertNotContains('SORTING_UNRECOGNIZED', $codes);
        self::assertNotContains('FILTER_VALUE_INVALID', $codes);
    }

    #[Test]
    public function theIncludeCodesFollowWhereIncludeIsCheckedOrRendered(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [
                new FakeRelationMetadata('comments', ['comments'], true),
                new WriteOnlyRelationMetadata(new FakeRelationMetadata('watchers', ['comments'], true, includable: false, relatedEndpoint: false)),
            ]],
        ));

        foreach (['INCLUSION_UNRECOGNIZED', 'INCLUSION_NOT_ALLOWED', 'INCLUSION_DEPTH_EXCEEDED'] as $code) {
            self::assertContains($code, $this->codes($document, '/articles/{id}/relationships/comments', 'get'), $code);
            foreach (['patch', 'post', 'delete'] as $method) {
                self::assertContains($code, $this->codes($document, '/articles/{id}/relationships/comments', $method), $code . ' on the echoed linkage of ' . $method);
                self::assertNotContains($code, $this->codes($document, '/articles/{id}/relationships/watchers', $method), $code . ': a write-only relation answers 204 and ignores include');
            }
            self::assertNotContains($code, $this->codes($document, '/articles/{id}', 'delete'), $code);
        }
    }

    #[Test]
    public function theNotFoundCodesFollowWhatTheOperationAddresses(): void
    {
        $document = $this->project($this->server(
            articles: [
                'relations' => [new FakeRelationMetadata('comments', ['comments'], true)],
                'actions' => [
                    new FakeActionMetadata('publish', ['POST']),
                    new FakeActionMetadata('purge', ['POST'], ActionScope::Collection),
                ],
            ],
        ));

        self::assertContains('RESOURCE_NOT_FOUND', $this->codes($document, '/articles/{id}', 'get'));
        self::assertContains('RESOURCE_NOT_FOUND', $this->codes($document, '/articles', 'post'), 'its linkage can name a missing resource');
        self::assertNotContains('RESOURCE_NOT_FOUND', $this->codes($document, '/comments', 'post'), 'a comment carries no linkage');
        self::assertContains('RESOURCE_NOT_FOUND', $this->codes($document, '/articles/{id}/-actions/publish', 'post'));
        self::assertNotContains('RESOURCE_NOT_FOUND', $this->codes($document, '/articles/-actions/purge', 'post'));

        self::assertContains('RELATIONSHIP_NOT_EXISTS', $this->codes($document, '/articles/{id}/comments', 'get'));
        self::assertContains('RELATIONSHIP_NOT_EXISTS', $this->codes($document, '/articles/{id}/relationships/comments', 'post'));
        self::assertNotContains('RELATIONSHIP_NOT_EXISTS', $this->codes($document, '/articles/{id}', 'get'));
        self::assertNotContains('RELATIONSHIP_NOT_EXISTS', $this->codes($document, '/articles/{id}', 'delete'));
    }

    #[Test]
    public function eachRelationshipGuardIsOfferedOnTheMutationsItGuards(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [
                new FakeRelationMetadata('comments', ['comments'], true),
                new FakeRelationMetadata('author', ['comments'], false),
            ]],
            atomic: true,
        ));

        $tags = '/articles/{id}/relationships/comments';
        self::assertContains('ADDITION_PROHIBITED', $this->codes($document, $tags, 'post'));
        self::assertNotContains('ADDITION_PROHIBITED', $this->codes($document, $tags, 'patch'));
        self::assertNotContains('ADDITION_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'));

        self::assertContains('REMOVAL_PROHIBITED', $this->codes($document, $tags, 'delete'));
        self::assertContains('REMOVAL_PROHIBITED', $this->codes($document, '/articles/{id}/relationships/author', 'patch'), 'a to-one is cleared with null');
        self::assertNotContains('REMOVAL_PROHIBITED', $this->codes($document, $tags, 'patch'), 'a to-many replacement is never a removal');
        self::assertContains('REMOVAL_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'), 'an update can clear an embedded relation');

        self::assertContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($document, $tags, 'patch'));
        self::assertContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'));
        self::assertNotContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($document, '/comments/{id}', 'patch'), 'a comment has no relation');
        self::assertNotContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($document, '/articles', 'post'));
    }

    #[Test]
    public function theBatchCanAddToARelationWhoseEndpointRefusesAdditions(): void
    {
        $refusing = $this->project($this->server(
            articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true, allowsAdd: false)]],
            atomic: true,
        ));
        self::assertContains('ADDITION_PROHIBITED', $this->codes($refusing, '/operations', 'post'));

        $plain = $this->project($this->server(atomic: true));
        self::assertNotContains('ADDITION_PROHIBITED', $this->codes($plain, '/operations', 'post'), 'no relation to add to');
    }

    #[Test]
    public function theBatchOffersTheWritesItRunsButNotTheChecksItsDocumentSkips(): void
    {
        $document = $this->project($this->server(atomic: true));

        foreach (['REQUEST_BODY_INVALID_JSON', 'DATA_MEMBER_MISSING', 'RESOURCE_ID_MISSING'] as $code) {
            self::assertContains($code, $this->codes($document, '/operations', 'post'), $code);
        }
        foreach (['LOCAL_ID_NOT_SUPPORTED', 'REQUIRED_TOP_LEVEL_MEMBERS_MISSING', 'RESOURCE_ID_CONFLICT', 'SORTING_UNSUPPORTED'] as $code) {
            self::assertNotContains($code, $this->codes($document, '/operations', 'post'), $code);
        }
        self::assertContains('RESOURCE_ID_CONFLICT', $this->codes($document, '/articles/{id}', 'patch'));
        self::assertNotContains('RESOURCE_ID_CONFLICT', $this->codes($document, '/articles', 'post'));
        self::assertNotContains('RESOURCE_ID_MISSING', $this->codes($document, '/articles', 'post'));
    }

    #[Test]
    public function theLinkageCodesNeedLinkage(): void
    {
        $document = $this->project($this->server(
            articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true, pivotFields: [Integer::make('position')->build()])]],
        ));

        self::assertContains('RESOURCE_IDENTIFIER_TYPE_MISSING', $this->codes($document, '/articles', 'post'));
        self::assertContains('RESOURCE_IDENTIFIER_TYPE_MISSING', $this->codes($document, '/articles/{id}/relationships/comments', 'post'));
        self::assertNotContains('RESOURCE_IDENTIFIER_TYPE_MISSING', $this->codes($document, '/comments', 'post'), 'a comment declares no relation');
        self::assertContains('RESOURCE_TYPE_UNACCEPTABLE', $this->codes($document, '/articles/{id}/relationships/comments', 'post'));
        self::assertNotContains('RELATIONSHIP_TYPE_INAPPROPRIATE', $this->codes($document, '/articles', 'post'), 'a resource reads linkage of the declared cardinality');
        self::assertContains('RELATIONSHIP_TYPE_INAPPROPRIATE', $this->codes($document, '/articles/{id}/relationships/comments', 'patch'));

        // A pivot value is cast like an attribute.
        self::assertContains('ATTRIBUTE_VALUE_INVALID', $this->codes($document, '/articles/{id}/relationships/comments', 'post'));
        self::assertNotContains('ATTRIBUTE_MEMBER_UNRECOGNIZED', $this->codes($document, '/articles/{id}/relationships/comments', 'post'));
    }

    #[Test]
    public function theAttributeCodesFollowTheAttributesTheHydratedTypeDeclares(): void
    {
        $document = $this->project(new ReadOnlyAttributeRejectingServerMetadata($this->server(
            articles: [
                'fields' => [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build(), Str::make('authorName')->on('author')->build()],
                'relations' => [new FakeRelationMetadata('author', ['comments'], false)],
                'actions' => [new FakeActionMetadata('import', ['POST'], inputMode: ActionInputMode::Document, inputType: 'tags')],
            ],
            comments: ['fields' => [Id::make()->example('1')->build(), Str::make('body')->example('Nice')->build(), Str::make('author')->readOnlyOnCreate()->build()]],
            tags: true,
        )));

        self::assertContains('RELATED_ATTRIBUTE_OWNER_MISSING', $this->codes($document, '/articles/{id}', 'patch'));
        self::assertNotContains('RELATED_ATTRIBUTE_OWNER_MISSING', $this->codes($document, '/comments/{id}', 'patch'));
        self::assertContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/comments', 'post'), 'a comment\'s author is read-only');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/comments/{id}', 'patch'), 'an update may write the author');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles', 'post'), 'no article attribute is ever read-only');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/tags', 'post'), 'a tag declares no attribute');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles/{id}/-actions/import', 'post'), 'the action hydrates a tag');
    }

    #[Test]
    public function aCodeThatNeedsAScopeIsOfferedWhereAScopeIsChecked(): void
    {
        $base = $this->server(articles: [
            'relations' => [new FakeRelationMetadata('comments', ['comments'], true)],
            'includablePaths' => ['comments'],
        ]);
        $server = new ScopeDeclaringServerMetadata(
            $base,
            ScopeTables::of($base, static fn(string $type, OperationKey $operation): array => $type === 'comments' && $operation->kind === OperationKind::Delete ? ['comments:write'] : []),
            schemes: ['oauth2' => SecurityScheme::oauth2(new OAuthFlows(clientCredentials: new OAuthFlow(['comments:write' => 'Write comments.'], tokenUrl: 'https://auth.example.com/token')))],
        );
        $document = $this->project($server);

        self::assertContains('INSUFFICIENT_SCOPE', $this->codes($document, '/comments/{id}', 'delete'), 'the operation needs a scope');
        self::assertContains('INSUFFICIENT_SCOPE', $this->codes($document, '/articles/{id}', 'get'), 'an include hop can need one');
        self::assertNotContains('INSUFFICIENT_SCOPE', $this->codes($document, '/comments/{id}', 'get'), 'nothing to include and no scope of its own');
        self::assertNotContains('INSUFFICIENT_SCOPE', $this->codes($document, '/articles/{id}', 'delete'));
    }

    #[Test]
    public function aRuleKeepsItsShapeNameWhateverItReaches(): void
    {
        $one = $this->project($this->server());
        $two = $this->project($this->server(articles: ['sorts' => [SortByField::make('title')]]));

        self::assertContains('Error400ParameterSortingUnsupported', $this->offered($one, $this->errorDocument($one, '/articles', 'get', '400')));
        self::assertContains('Error400ParameterSortingUnsupported', $this->offered($two, $this->errorDocument($two, '/comments', 'get', '400')));
    }

    #[Test]
    public function aCodeNoRuleNamesIsOfferedEverywhere(): void
    {
        self::assertTrue(ErrorCoverage::coversEveryOperation(\stdClass::class));
        self::assertTrue(ErrorCoverage::coversEveryOperation(\haddowg\JsonApi\Exception\QueryParamUnrecognized::class));
        self::assertFalse(ErrorCoverage::coversEveryOperation(\haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported::class));
        self::assertCount(\count(ErrorCatalog::core()->exceptions()), ErrorCoverage::RULES);
    }

    #[Test]
    public function aWriteProhibitedAttributeNeedsAnAttributeThatCanBeReadOnly(): void
    {
        $document = $this->project(new ReadOnlyAttributeRejectingServerMetadata($this->server(
            articles: ['fields' => [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->readOnlyOnUpdate(static fn(JsonApiRequestInterface $request): bool => true)->build()]],
            comments: ['fields' => [Id::make()->example('1')->build(), Str::make('body')->example('Nice')->build()]],
        )));

        self::assertContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles/{id}', 'patch'), 'a predicate can make the title read-only');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/articles', 'post'), 'the predicate gates updates only');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/comments', 'post'), 'the body is never read-only');
        self::assertNotContains('ATTRIBUTE_WRITE_PROHIBITED', $this->codes($document, '/comments/{id}', 'patch'));
    }

    #[Test]
    public function aRelationshipGuardFollowsTheRelationsPredicatesAndTheDataLayersRefusals(): void
    {
        $plain = $this->project($this->server(
            articles: [
                'fields' => [
                    Id::make()->example('1')->build(),
                    HasMany::make('comments', 'comments')->build(),
                    BelongsTo::make('author', 'comments')->build(),
                ],
                'relations' => [
                    new FakeRelationMetadata('comments', ['comments'], true, canRefuseDetachment: false),
                    new FakeRelationMetadata('author', ['comments'], false, canRefuseDetachment: false),
                ],
            ],
        ));
        $comments = '/articles/{id}/relationships/comments';
        foreach ([[$comments, 'post', 'ADDITION_PROHIBITED'], [$comments, 'delete', 'REMOVAL_PROHIBITED'], [$comments, 'patch', 'FULL_REPLACEMENT_PROHIBITED'], ['/articles/{id}/relationships/author', 'patch', 'FULL_REPLACEMENT_PROHIBITED'], ['/articles/{id}/relationships/author', 'patch', 'REMOVAL_PROHIBITED'], ['/articles/{id}', 'patch', 'FULL_REPLACEMENT_PROHIBITED'], ['/articles/{id}', 'patch', 'REMOVAL_PROHIBITED']] as [$path, $method, $code]) {
            self::assertNotContains($code, $this->codes($plain, $path, $method), "{$code} on {$method} {$path}: nothing refuses it");
        }

        $guarded = $this->project($this->server(
            articles: [
                'fields' => [
                    Id::make()->example('1')->build(),
                    HasMany::make('comments', 'comments')
                        ->cannotAdd(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)
                        ->cannotRemove(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)
                        ->build(),
                    BelongsTo::make('author', 'comments')->cannotReplace(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)->build(),
                ],
                'relations' => [
                    new FakeRelationMetadata('comments', ['comments'], true, canRefuseDetachment: false),
                    new FakeRelationMetadata('author', ['comments'], false),
                ],
            ],
        ));
        self::assertContains('ADDITION_PROHIBITED', $this->codes($guarded, $comments, 'post'));
        self::assertContains('REMOVAL_PROHIBITED', $this->codes($guarded, $comments, 'delete'));
        self::assertNotContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($guarded, $comments, 'patch'), 'nothing guards the replacement');
        self::assertContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($guarded, '/articles/{id}/relationships/author', 'patch'), 'a predicate and the data layer guard it');
        self::assertContains('REMOVAL_PROHIBITED', $this->codes($guarded, '/articles/{id}/relationships/author', 'patch'), 'the data layer can refuse to clear it');
        self::assertContains('FULL_REPLACEMENT_PROHIBITED', $this->codes($guarded, '/articles/{id}', 'patch'), 'an update meets the data layer\'s refusal');
        self::assertContains('REMOVAL_PROHIBITED', $this->codes($guarded, '/articles/{id}', 'patch'), 'an update can clear the to-one');
    }

    #[Test]
    public function anUndecodableIdNeedsAnEncodedId(): void
    {
        $document = $this->project($this->server(
            articles: ['allowsClientId' => true, 'fields' => [Id::make()->encodeUsing(new ReversingIdEncoder())->example('1')->build()]],
            comments: ['allowsClientId' => true],
        ));

        self::assertContains('RESOURCE_ID_UNDECODABLE', $this->codes($document, '/articles', 'post'));
        self::assertNotContains('RESOURCE_ID_UNDECODABLE', $this->codes($document, '/comments', 'post'), 'the id is taken as sent');
    }

    #[Test]
    public function theIncludeSafeguardCodesNeedASafeguardThatCanRefuse(): void
    {
        $open = ['allowed' => null, 'excluded' => [], 'depth' => null];
        $relations = [
            'fields' => [Id::make()->example('1')->build(), HasMany::make('comments', 'comments')->build()],
            'relations' => [new FakeRelationMetadata('comments', ['comments'], true)],
        ];
        $unlimited = $this->project($this->server(
            articles: [...$relations, 'includeSafeguards' => $open],
            comments: ['includeSafeguards' => $open],
            server: ['maxIncludeDepth' => null],
        ));
        foreach (['INCLUSION_NOT_ALLOWED', 'INCLUSION_DEPTH_EXCEEDED'] as $code) {
            self::assertNotContains($code, $this->codes($unlimited, '/articles/{id}', 'get'), "{$code}: nothing limits the include");
            self::assertNotContains($code, $this->codes($unlimited, '/articles/{id}/comments', 'get'), $code);
        }
        self::assertContains('INCLUSION_UNRECOGNIZED', $this->codes($unlimited, '/articles/{id}', 'get'));

        $limited = $this->project($this->server(
            articles: [...$relations, 'includeSafeguards' => ['allowed' => ['comments'], 'excluded' => [], 'depth' => null]],
            comments: ['includeSafeguards' => ['depth' => 2]],
            server: ['maxIncludeDepth' => null],
        ));
        self::assertContains('INCLUSION_NOT_ALLOWED', $this->codes($limited, '/articles/{id}', 'get'), 'an allow-list refuses the rest');
        self::assertNotContains('INCLUSION_DEPTH_EXCEEDED', $this->codes($limited, '/articles/{id}', 'get'));
        self::assertContains('INCLUSION_DEPTH_EXCEEDED', $this->codes($limited, '/comments/{id}', 'get'), 'a comment caps its depth');
        self::assertContains('INCLUSION_DEPTH_EXCEEDED', $this->codes($limited, '/articles/{id}/comments', 'get'), 'a related read is rooted at the comment');

        $serverDepth = $this->project($this->server(articles: ['includeSafeguards' => $open], comments: ['includeSafeguards' => $open], server: ['maxIncludeDepth' => 3]));
        self::assertContains('INCLUSION_DEPTH_EXCEEDED', $this->codes($serverDepth, '/articles/{id}', 'get'), 'the server default applies');

        $conditional = $this->project($this->server(
            articles: [
                'fields' => [Id::make()->example('1')->build(), HasMany::make('comments', 'comments')->cannotBeIncluded(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)->build()],
                'relations' => [new FakeRelationMetadata('comments', ['comments'], true)],
                'includeSafeguards' => $open,
            ],
            comments: ['includeSafeguards' => $open],
            server: ['maxIncludeDepth' => null],
        ));
        self::assertContains('INCLUSION_NOT_ALLOWED', $this->codes($conditional, '/articles/{id}', 'get'), 'a predicate can refuse the comments');

        $undeclared = $this->project($this->server(articles: $relations));
        self::assertContains('INCLUSION_NOT_ALLOWED', $this->codes($undeclared, '/articles/{id}', 'get'));
        self::assertContains('INCLUSION_DEPTH_EXCEEDED', $this->codes($undeclared, '/articles/{id}', 'get'));
    }

    #[Test]
    public function anUnsupportedIncludeIsOfferedOnlyWhereAHandlerDeclaresIt(): void
    {
        self::assertNotContains('INCLUSION_UNSUPPORTED', $this->codes($this->project($this->server()), '/articles/{id}', 'get'), 'nothing raises it by default');

        $document = $this->project($this->server(articles: ['rejectsIncludeOn' => ['fetchOne']]));
        self::assertContains('INCLUSION_UNSUPPORTED', $this->codes($document, '/articles/{id}', 'get'));
        self::assertNotContains('INCLUSION_UNSUPPORTED', $this->codes($document, '/articles', 'get'));
    }

    #[Test]
    public function aTakenClientIdIsOfferedWhereTheDataLayerDetectsIt(): void
    {
        $document = $this->project($this->server(
            articles: ['allowsClientId' => true],
            comments: ['allowsClientId' => true, 'detectsClientIdCollisions' => false],
        ));

        self::assertContains('CLIENT_GENERATED_ID_ALREADY_EXISTS', $this->codes($document, '/articles', 'post'));
        self::assertNotContains('CLIENT_GENERATED_ID_ALREADY_EXISTS', $this->codes($document, '/comments', 'post'), 'the store\'s own error answers');
    }

    #[Test]
    public function anUnknownFieldsetMemberNeedsStrictQueryParameters(): void
    {
        self::assertContains('FIELDSET_MEMBER_UNRECOGNIZED', $this->codes($this->project($this->server()), '/articles/{id}', 'delete'));

        $lenient = $this->project($this->server(server: ['strictQueryParameters' => false]));
        self::assertNotContains('FIELDSET_MEMBER_UNRECOGNIZED', $this->codes($lenient, '/articles/{id}', 'get'));
        self::assertContains('QUERY_PARAM_UNRECOGNIZED', $this->codes($lenient, '/articles/{id}', 'get'), 'the family names are checked anyway');
    }

    #[Test]
    public function theBodyValidationCodesNeedTheValidationThatRaisesThem(): void
    {
        $relations = ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]];
        $validated = $this->project($this->server(articles: $relations));
        self::assertContains('REQUEST_BODY_INVALID_JSON_API', $this->codes($validated, '/articles/{id}/relationships/comments', 'patch'));
        self::assertContains('RESPONSE_BODY_INVALID_JSON_API', $this->codes($validated, '/articles/{id}', 'get'));

        $members = $this->project($this->server(articles: $relations, server: ['validatesRequestDocuments' => false, 'validatesResponseDocuments' => false]));
        self::assertContains('REQUEST_BODY_INVALID_JSON_API', $this->codes($members, '/articles', 'post'), 'a resource object\'s members are still checked');
        self::assertContains('REQUEST_BODY_INVALID_JSON_API', $this->codes($members, '/articles/{id}', 'patch'));
        self::assertNotContains('REQUEST_BODY_INVALID_JSON_API', $this->codes($members, '/articles/{id}/relationships/comments', 'patch'));
        foreach (['RESPONSE_BODY_INVALID_JSON', 'RESPONSE_BODY_INVALID_JSON_API'] as $code) {
            self::assertNotContains($code, $this->codes($members, '/articles/{id}', 'get'), $code);
        }

        $none = $this->project($this->server(server: ['validatesRequestDocuments' => false, 'checksResourceObjectMembers' => false]));
        self::assertNotContains('REQUEST_BODY_INVALID_JSON_API', $this->codes($none, '/articles', 'post'));
    }

    #[Test]
    public function aCreateMissesLinkageOnlyThroughARelationResolvedAgainstStorage(): void
    {
        $resolved = $this->project($this->server(articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true, canMissLinkage: false)]]));
        self::assertNotContains('RESOURCE_NOT_FOUND', $this->codes($resolved, '/articles', 'post'), 'the linkage always resolves');
        self::assertContains('RESOURCE_NOT_FOUND', $this->codes($resolved, '/articles/{id}', 'patch'), 'the update looks the article up');

        $readOnly = $this->project($this->server(articles: [
            'fields' => [Id::make()->example('1')->build(), HasMany::make('comments', 'comments')->readOnlyOnCreate()->build()],
            'relations' => [new FakeRelationMetadata('comments', ['comments'], true)],
        ]));
        self::assertNotContains('RESOURCE_NOT_FOUND', $this->codes($readOnly, '/articles', 'post'), 'a create cannot set the comments');

        $unknown = $this->project($this->server(articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]]));
        self::assertContains('RESOURCE_NOT_FOUND', $this->codes($unknown, '/articles', 'post'));
    }

    #[Test]
    public function aRelationshipReadIsAbsentOnlyForARelationThatCanBeHidden(): void
    {
        $visible = $this->project($this->server(articles: [
            'fields' => [Id::make()->example('1')->build(), HasMany::make('comments', 'comments')->build(), HasMany::make('drafts', 'comments')->hidden(static fn(mixed $model, JsonApiRequestInterface $request): bool => true)->build()],
            'relations' => [new FakeRelationMetadata('comments', ['comments'], true), new FakeRelationMetadata('drafts', ['comments'], true)],
        ]));
        self::assertNotContains('RELATIONSHIP_NOT_EXISTS', $this->codes($visible, '/articles/{id}/comments', 'get'));
        self::assertNotContains('RELATIONSHIP_NOT_EXISTS', $this->codes($visible, '/articles/{id}/relationships/comments', 'get'));
        self::assertContains('RELATIONSHIP_NOT_EXISTS', $this->codes($visible, '/articles/{id}/relationships/comments', 'post'), 'a mutation reads the relation from the document');
        self::assertContains('RELATIONSHIP_NOT_EXISTS', $this->codes($visible, '/articles/{id}/drafts', 'get'), 'a predicate can hide the drafts');
        self::assertContains('RELATIONSHIP_NOT_EXISTS', $this->codes($visible, '/articles/{id}/relationships/drafts', 'get'));
    }

    #[Test]
    public function theSecurityCodesFollowWhatTheAdapterSaysItsSecurityLayerCanRefuse(): void
    {
        $everywhere = $this->project($this->server(atomic: true));
        self::assertContains('ACCESS_DENIED', $this->codes($everywhere, '/comments/{id}', 'get'), 'undeclared, every operation');
        self::assertContains('Error403', $this->offered($everywhere, 'ErrorDocument403'));

        $document = $this->project($this->server(atomic: true, server: [
            'canDenyAccess' => static fn(?TypeMetadataInterface $type, OperationKey $operation): bool => $type?->type() === 'articles' && $operation->kind === OperationKind::Delete,
            'canRequireAuthentication' => static fn(?TypeMetadataInterface $type, OperationKey $operation): bool => $type === null || $operation->method !== 'GET',
        ]));
        self::assertContains('ACCESS_DENIED', $this->codes($document, '/articles/{id}', 'delete'));
        self::assertNotContains('ACCESS_DENIED', $this->codes($document, '/articles/{id}', 'get'));
        self::assertContains('ACCESS_DENIED', $this->codes($document, '/operations', 'post'), 'a batch entry can run the delete');
        self::assertContains('AUTHENTICATION_REQUIRED', $this->codes($document, '/comments', 'post'));
        self::assertContains('AUTHENTICATION_REQUIRED', $this->codes($document, '/operations', 'post'));
        self::assertNotContains('AUTHENTICATION_REQUIRED', $this->codes($document, '/comments', 'get'));

        $readsOnly = $this->project($this->server(atomic: true, server: [
            'canDenyAccess' => static fn(?TypeMetadataInterface $type, OperationKey $operation): bool => $operation->kind === OperationKind::FetchOne,
        ]));
        self::assertContains('ACCESS_DENIED', $this->codes($readsOnly, '/comments/{id}', 'get'));
        self::assertNotContains('ACCESS_DENIED', $this->codes($readsOnly, '/operations', 'post'), 'a batch runs no read');
    }

    #[Test]
    public function aHandWrittenHydratorRefusesTheWrongCardinalityOnlyWhenItChecks(): void
    {
        $checking = FakeTypeMetadata::standalone('notes', operations: [OperationType::Create, OperationType::FetchOne]);
        $trusting = FakeTypeMetadata::standalone('memos', operations: [OperationType::Create, OperationType::FetchOne], checksRelationshipCardinality: false);
        $document = $this->project($this->server(extra: [$checking, $trusting]));

        self::assertContains('RELATIONSHIP_TYPE_INAPPROPRIATE', $this->codes($document, '/notes', 'post'));
        self::assertNotContains('RELATIONSHIP_TYPE_INAPPROPRIATE', $this->codes($document, '/memos', 'post'));
    }

    #[Test]
    public function aRelationshipMutationIgnoresSortAndFilter(): void
    {
        $document = $this->project($this->server(articles: ['relations' => [new FakeRelationMetadata('comments', ['comments'], true)]]));

        foreach (['patch', 'post', 'delete'] as $method) {
            foreach (['SORTING_UNSUPPORTED', 'FILTERING_UNRECOGNIZED'] as $code) {
                self::assertNotContains($code, $this->codes($document, '/articles/{id}/relationships/comments', $method), "{$code} on {$method}");
            }
        }
        self::assertContains('FILTERING_UNRECOGNIZED', $this->codes($document, '/articles/{id}/relationships/comments', 'get'));
    }

    /**
     * An `articles` and a `comments` type, fully writable, and a `tags` type declaring no
     * attribute when asked for; each type's named arguments override the defaults.
     *
     * @param array<string, mixed> $articles
     * @param array<string, mixed> $comments
     * @param list<string>         $profiles
     * @param array<string, mixed> $server   FakeServerMetadata's own named arguments
     * @param list<FakeTypeMetadata> $extra  further types
     */
    private function server(array $articles = [], array $comments = [], bool $atomic = false, array $profiles = [], bool $tags = false, array $server = [], array $extra = []): FakeServerMetadata
    {
        $types = [
            // @phpstan-ignore argument.type (the overrides are FakeTypeMetadata::resource()'s own named arguments)
            FakeTypeMetadata::resource(...[
                'type' => 'articles',
                'fields' => [Id::make()->example('1')->build(), Str::make('title')->example('Hello')->build()],
                ...$articles,
            ]),
            // @phpstan-ignore argument.type (the overrides are FakeTypeMetadata::resource()'s own named arguments)
            FakeTypeMetadata::resource(...[
                'type' => 'comments',
                'fields' => [Id::make()->example('1')->build(), Str::make('body')->example('Nice')->build()],
                ...$comments,
            ]),
        ];
        if ($tags) {
            $types[] = FakeTypeMetadata::resource(type: 'tags', fields: [Id::make()->example('1')->build()], operations: [OperationType::Create, OperationType::FetchOne]);
        }
        $types = [...$types, ...$extra];

        // @phpstan-ignore argument.type (the overrides are FakeServerMetadata's own named arguments)
        return new FakeServerMetadata(...[
            'title' => 'API',
            'version' => '1.0.0',
            'types' => $types,
            'atomicOperations' => $atomic ? new FakeAtomicOperationsMetadata() : null,
            'profiles' => $profiles,
            ...$server,
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function project(ServerMetadataInterface $server): array
    {
        return (new OpenApiProjector())->project($server)->toArray();
    }

    /**
     * Every code an operation's error responses offer, across its statuses.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function codes(array $document, string $path, string $method): array
    {
        $codes = [];
        foreach ($this->arr($document, 'paths', $path, $method, 'responses') as $status => $response) {
            if ((int) $status < 400) {
                continue;
            }
            self::assertIsArray($response);
            $ref = $response['$ref'] ?? null;
            if (\is_string($ref)) {
                $response = $this->arr($document, 'components', 'responses', \substr($ref, \strlen('#/components/responses/')));
            }
            $schema = $this->at($response, 'content', 'application/vnd.api+json', 'schema', '$ref');
            self::assertIsString($schema);
            foreach ($this->offered($document, \substr($schema, \strlen('#/components/schemas/'))) as $shape) {
                if ($shape === 'Error') {
                    continue;
                }
                foreach ($this->arr($document, 'components', 'schemas', $shape, 'allOf', '1', 'properties', 'code', 'anyOf') as $branch) {
                    self::assertIsArray($branch);
                    self::assertIsString($branch['const'] ?? null);
                    $codes[] = $branch['const'];
                }
            }
        }

        return $codes;
    }

    /**
     * The shapes an error document offers, behind the generic `Error`.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string>
     */
    private function offered(array $document, string $errorDocument): array
    {
        $shapes = [];
        foreach ($this->arr($document, 'components', 'schemas', $errorDocument, 'properties', 'errors', 'items', 'anyOf') as $branch) {
            self::assertIsArray($branch);
            self::assertIsString($branch['$ref'] ?? null);
            $shapes[] = \substr($branch['$ref'], \strlen('#/components/schemas/'));
        }

        return $shapes;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private function errorDocument(array $document, string $path, string $method, string $status): string
    {
        $ref = $this->at($document, 'paths', $path, $method, 'responses', $status, 'content', 'application/vnd.api+json', 'schema', '$ref');
        self::assertIsString($ref);

        return \substr($ref, \strlen('#/components/schemas/'));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function at(array $value, string ...$keys): mixed
    {
        $cursor = $value;
        foreach ($keys as $key) {
            self::assertIsArray($cursor);
            self::assertArrayHasKey($key, $cursor, \implode(' > ', $keys));
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function arr(array $value, string ...$keys): array
    {
        $found = $this->at($value, ...$keys);
        self::assertIsArray($found);

        return $found;
    }
}
