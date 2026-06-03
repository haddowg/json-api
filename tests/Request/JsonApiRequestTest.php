<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Request;

use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamMalformed;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\TopLevelMemberNotAllowed;
use haddowg\JsonApi\Exception\TopLevelMembersIncompatible;
use haddowg\JsonApi\Request\JsonApiRequest;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JsonApiRequest's JSON:API-specific parsing and validation.
 *
 * JsonApiRequest takes only a PSR-7 request; requests are built with Nyholm\Psr7,
 * and createRequestWithJsonBody uses withParsedBody() (PSR-7) to supply a JSON:API
 * body. Validation surfaces failures by throwing typed exceptions directly.
 */
final class JsonApiRequestTest extends TestCase
{
    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateJsonApiContentTypeHeader(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateJsonApiContentTypeHeaderWithSemicolon(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json;');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateEmptyContentTypeHeader(): void
    {
        $request = $this->createRequestWithHeader('content-type', '');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateHtmlContentTypeHeader(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'text/html; charset=utf-8');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateMultipleMediaTypeContentTypeHeader(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json, text/*;q=0.3, text/html;q=0.7');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateCaseInsensitiveContentTypeHeader(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'Application/vnd.Api+JSON, text/*;q=0.3, text/html;Q=0.7');

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    #[Group('spec:extensions-and-profiles')]
    public function validateContentTypeHeaderWithExtMediaTypeIsWellFormed(): void
    {
        // `ext` is a permitted media-type parameter, so the header is well-formed.
        // Whether the extension is *supported* is negotiated separately (415 lives
        // in RequestValidator, not here) — see RequestValidatorTest.
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json; ext="https://example.com/ext/a"');

        $request->validateContentTypeHeader();

        $this->addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateInvalidContentTypeHeaderWithWhitespaceBeforeParameter(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json ; charset=utf-8');

        $this->expectException(MediaTypeUnsupported::class);

        $request->validateContentTypeHeader();
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateContentTypeHeaderWithJsonApiProfileMediaTypeParameter(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile=https://example.com/profiles/last-modified',
        );

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateContentTypeHeaderWithInvalidMediaTypeParameter(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json; Charset=utf-8');

        $this->expectException(MediaTypeUnsupported::class);

        $request->validateContentTypeHeader();
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateAcceptHeaderWithJsonApiMediaType(): void
    {
        $request = $this->createRequestWithHeader('accept', 'application/vnd.api+json');

        $request->validateAcceptHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateAcceptHeaderWithJsonApiProfileMediaTypeParameter(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json; Profile = https://example.com/profiles/last-modified',
        );

        $request->validateContentTypeHeader();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function validateAcceptHeaderWithInvalidMediaTypeParameters(): void
    {
        $request = $this->createRequestWithHeader('accept', 'application/vnd.api+json; ext="ext1,ext2"; charset=utf-8; lang=en');

        $this->expectException(MediaTypeUnacceptable::class);

        $request->validateAcceptHeader();
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function validateEmptyQueryParams(): void
    {
        $request = $this->createRequestWithQueryParams([]);

        $request->validateQueryParams();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function validateBasicQueryParams(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => ['user' => 'name, address'],
                'include' => ['contacts'],
                'sort' => ['-name'],
                'page' => ['number' => '1'],
                'filter' => ['age' => '21'],
                'profile' => '',
            ],
        );

        $request->validateQueryParams();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:fetching-data')]
    public function validateInvalidQueryParams(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => ['user' => 'name, address'],
                'paginate' => ['-name'],
            ],
        );

        $this->expectException(QueryParamUnrecognized::class);

        $request->validateQueryParams();
    }

    #[Test]
    public function validateTopLevelMembersWithoutBody(): void
    {
        $request = $this->createRequest();

        $request->validateTopLevelMembers();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateTopLevelMembersWhenEmpty(): void
    {
        $request = $this->createRequestWithJsonBody(
            [],
        );

        // FIXME: known edge case in top-level member validation
        // self::expectException(RequiredTopLevelMembersMissing::class);

        $request->validateTopLevelMembers();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateTopLevelMembersWhenDataAndErrors(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [],
                'errors' => [],
            ],
        );

        $this->expectException(TopLevelMembersIncompatible::class);

        $request->validateTopLevelMembers();
    }

    #[Test]
    public function validateTopLevelMembersWhenIncludedWithoutData(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'errors' => [],
                'included' => [],
            ],
        );

        $this->expectException(TopLevelMemberNotAllowed::class);

        $request->validateTopLevelMembers();
    }

    #[Test]
    public function validateTopLevelMembersWhenData(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [],
            ],
        );

        $request->validateTopLevelMembers();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateTopLevelMembersWhenDataAndIncluded(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [],
                'included' => [],
            ],
        );

        $request->validateTopLevelMembers();

        self::addToAssertionCount(1);
    }

    #[Test]
    public function validateTopLevelMembersWhenErrors(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'errors' => [],
            ],
        );

        $request->validateTopLevelMembers();

        self::addToAssertionCount(1);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function getIncludedFieldsWhenEmpty(): void
    {
        $request = $this->createRequestWithQueryParams([]);

        $includedFields = $request->getIncludedFields('');

        self::assertEquals([], $includedFields);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function getIncludedFieldsForResource(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => [
                    'book' => 'title,pages',
                ],
            ],
        );

        $includedFields = $request->getIncludedFields('book');

        self::assertEquals(['title', 'pages'], $includedFields);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function getIncludedFieldsForUnspecifiedResource(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => [
                    'book' => 'title,pages',
                ],
            ],
        );

        $includedFields = $request->getIncludedFields('newspaper');

        self::assertEquals([], $includedFields);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function getIncludedFieldWhenMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => '',
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getIncludedFields('');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function getIncludedFieldWhenFieldMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => [
                    'book' => [],
                ],
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getIncludedFields('');
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function isIncludedFieldWhenAllFieldsRequested(): void
    {
        $request = $this->createRequestWithQueryParams(['fields' => []]);
        self::assertTrue($request->isIncludedField('book', 'title'));

        $request = $this->createRequestWithQueryParams([]);
        self::assertTrue($request->isIncludedField('book', 'title'));
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function isIncludedFieldWhenNoFieldRequested(): void
    {
        $request = $this->createRequestWithQueryParams(['fields' => ['book1' => '']]);

        $isIncludedField = $request->isIncludedField('book1', 'title');

        self::assertFalse($isIncludedField);
    }

    #[Test]
    #[Group('spec:sparse-fieldsets')]
    public function isIncludedFieldWhenGivenFieldIsSpecified(): void
    {
        $request = $this->createRequestWithQueryParams(['fields' => ['book' => 'title,pages']]);

        $isIncludedField = $request->isIncludedField('book', 'title');

        self::assertTrue($isIncludedField);
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function hasIncludedRelationshipsWhenTrue(): void
    {
        $request = $this->createRequestWithQueryParams(['include' => 'authors']);

        $hasIncludedRelationships = $request->hasIncludedRelationships();

        self::assertTrue($hasIncludedRelationships);
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function hasIncludedRelationshipsWhenFalse(): void
    {
        $queryParams = ['include' => ''];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse($request->hasIncludedRelationships());

        $queryParams = [];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse($request->hasIncludedRelationships());
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function getIncludedEmptyRelationshipsWhenEmpty(): void
    {
        $baseRelationshipPath = 'book';
        $includedRelationships = [];
        $queryParams = ['include' => ''];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($includedRelationships, $request->getIncludedRelationships($baseRelationshipPath));

        $baseRelationshipPath = 'book';
        $includedRelationships = [];
        $queryParams = [];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($includedRelationships, $request->getIncludedRelationships($baseRelationshipPath));
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function getIncludedRelationshipsForPrimaryResource(): void
    {
        $baseRelationshipPath = '';
        $includedRelationships = ['authors'];
        $queryParams = ['include' => \implode(',', $includedRelationships)];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($includedRelationships, $request->getIncludedRelationships($baseRelationshipPath));
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function getIncludedRelationshipsForEmbeddedResource(): void
    {
        $baseRelationshipPath = 'book';
        $includedRelationships = ['authors'];
        $queryParams = ['include' => 'book,book.authors'];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($includedRelationships, $request->getIncludedRelationships($baseRelationshipPath));
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function getIncludedRelationshipsForMultipleEmbeddedResource(): void
    {
        $baseRelationshipPath = 'book.authors';
        $includedRelationships = ['contacts', 'address'];
        $queryParams = ['include' => 'book,book.authors,book.authors.contacts,book.authors.address'];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($includedRelationships, $request->getIncludedRelationships($baseRelationshipPath));
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function getIncludedRelationshipsWhenMalformed(): void
    {
        $this->expectException(QueryParamMalformed::class);

        $queryParams = ['include' => []];

        $request = $this->createRequestWithQueryParams($queryParams);
        $request->getIncludedRelationships('');
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function isIncludedRelationshipForPrimaryResourceWhenEmpty(): void
    {
        $baseRelationshipPath = '';
        $requiredRelationship = 'authors';
        $defaultRelationships = [];
        $queryParams = ['include' => ''];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse(
            $request->isIncludedRelationship($baseRelationshipPath, $requiredRelationship, $defaultRelationships),
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function isIncludedRelationshipForPrimaryResourceWhenEmptyWithDefault(): void
    {
        $baseRelationshipPath = '';
        $requiredRelationship = 'authors';
        $defaultRelationships = ['publisher' => true];
        $queryParams = [];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse(
            $request->isIncludedRelationship($baseRelationshipPath, $requiredRelationship, $defaultRelationships),
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function isIncludedRelationshipForPrimaryResourceWithDefault(): void
    {
        $baseRelationshipPath = '';
        $requiredRelationship = 'authors';
        $defaultRelationships = ['publisher' => true];
        $queryParams = ['include' => 'editors'];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse(
            $request->isIncludedRelationship($baseRelationshipPath, $requiredRelationship, $defaultRelationships),
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function isIncludedRelationshipForEmbeddedResource(): void
    {
        $baseRelationshipPath = 'authors';
        $requiredRelationship = 'contacts';
        $defaultRelationships = [];
        $queryParams = ['include' => 'authors,authors.contacts'];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertTrue(
            $request->isIncludedRelationship($baseRelationshipPath, $requiredRelationship, $defaultRelationships),
        );
    }

    #[Test]
    #[Group('spec:inclusion-of-related-resources')]
    public function isIncludedRelationshipForEmbeddedResourceWhenDefaulted(): void
    {
        $baseRelationshipPath = 'authors';
        $requiredRelationship = 'contacts';
        $defaultRelationships = ['contacts' => true];
        $queryParams = ['include' => ''];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertFalse(
            $request->isIncludedRelationship($baseRelationshipPath, $requiredRelationship, $defaultRelationships),
        );
    }

    #[Test]
    #[Group('spec:sorting')]
    public function getSortingWhenEmpty(): void
    {
        $sorting = [];
        $queryParams = ['sort' => ''];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($sorting, $request->getSorting());
    }

    #[Test]
    #[Group('spec:sorting')]
    public function getSortingWhenNotEmpty(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'sort' => 'name,age,sex',
            ],
        );

        $sorting = $request->getSorting();

        self::assertEquals(['name', 'age', 'sex'], $sorting);
    }

    #[Test]
    #[Group('spec:sorting')]
    public function getSortingWhenMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'sort' => ['name' => 'asc'],
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getSorting();
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPaginationWhenEmpty(): void
    {
        $pagination = [];
        $queryParams = ['page' => []];

        $request = $this->createRequestWithQueryParams($queryParams);
        self::assertEquals($pagination, $request->getPagination());
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPaginationWhenNotEmpty(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'page' => ['number' => '1', 'size' => '10'],
            ],
        );

        $pagination = $request->getPagination();

        self::assertEquals(['number' => '1', 'size' => '10'], $pagination);
    }

    #[Test]
    #[Group('spec:pagination')]
    public function getPaginationWhenMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'page' => '',
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getPagination();
    }

    #[Test]
    #[Group('spec:filtering')]
    public function getFilteringWhenEmpty(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'filter' => [],
            ],
        );

        $filtering = $request->getFiltering();

        self::assertEmpty($filtering);
    }

    #[Test]
    #[Group('spec:filtering')]
    public function getFilteringWhenNotEmpty(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'filter' => ['name' => 'John'],
            ],
        );

        $filtering = $request->getFiltering();

        self::assertEquals(['name' => 'John'], $filtering);
    }

    #[Test]
    #[Group('spec:filtering')]
    public function getFilteringWhenMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'filter' => '',
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getFiltering();
    }

    #[Test]
    #[Group('spec:filtering')]
    public function getFilteringParam(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'filter' => ['name' => 'John'],
            ],
        );

        $filteringParam = $request->getFilteringParam('name');

        self::assertEquals('John', $filteringParam);
    }

    #[Test]
    #[Group('spec:filtering')]
    public function getDefaultFilteringParamWhenNotFound(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'filter' => ['name' => 'John'],
            ],
        );

        $filteringParam = $request->getFilteringParam('age', false);

        self::assertFalse($filteringParam);
    }

    #[Test]
    public function getAppliedProfilesWhenEmpty(): void
    {
        $request = $this->createRequestWithHeader('content-type', 'application/vnd.api+json');

        $profiles = $request->getAppliedProfiles();

        self::assertEmpty($profiles);
    }

    #[Test]
    public function getAppliedProfilesWhenOneProfile(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile=https://example.com/profiles/last-modified',
        );

        $profiles = $request->getAppliedProfiles();

        self::assertEquals(
            [
                'https://example.com/profiles/last-modified',
            ],
            $profiles,
        );
    }

    #[Test]
    public function getAppliedProfilesWhenTwoProfiles(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $profiles = $request->getAppliedProfiles();

        self::assertEquals(
            [
                'https://example.com/profiles/last-modified',
                'https://example.com/profiles/created',
            ],
            $profiles,
        );
    }

    #[Test]
    public function getAppliedProfilesWhenMultipleJsonApiContentTypes(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile = https://example.com/profiles/last-modified, ' .
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $profiles = $request->getAppliedProfiles();

        self::assertEquals(
            [
                'https://example.com/profiles/last-modified',
                'https://example.com/profiles/created',
            ],
            $profiles,
        );
    }

    #[Test]
    public function isProfileAppliedWhenTrue(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $isProfileApplied = $request->isProfileApplied('https://example.com/profiles/created');

        self::assertTrue($isProfileApplied);
    }

    #[Test]
    public function isProfileAppliedWhenFalse(): void
    {
        $request = $this->createRequestWithHeader(
            'content-type',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $isProfileApplied = $request->isProfileApplied('https://example.com/profiles/inexistent-profile');

        self::assertFalse($isProfileApplied);
    }

    #[Test]
    public function getRequestedProfilesWhenEmpty(): void
    {
        $request = $this->createRequestWithHeader('accept', 'application/vnd.api+json');

        $profiles = $request->getRequestedProfiles();

        self::assertEmpty($profiles);
    }

    #[Test]
    public function getRequestedProfilesWhenTwoProfiles(): void
    {
        $request = $this->createRequestWithHeader(
            'accept',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $profiles = $request->getRequestedProfiles();

        self::assertEquals(
            [
                'https://example.com/profiles/last-modified',
                'https://example.com/profiles/created',
            ],
            $profiles,
        );
    }

    #[Test]
    public function isProfileRequestedWhenTrue(): void
    {
        $request = $this->createRequestWithHeader(
            'accept',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $isProfileRequested = $request->isProfileRequested('https://example.com/profiles/created');

        self::assertTrue($isProfileRequested);
    }

    #[Test]
    public function isProfileRequestedWhenFalse(): void
    {
        $request = $this->createRequestWithHeader(
            'accept',
            'application/vnd.api+json;profile="https://example.com/profiles/last-modified https://example.com/profiles/created"',
        );

        $isProfileRequested = $request->isProfileRequested('https://example.com/profiles/inexistent-profile');

        self::assertFalse($isProfileRequested);
    }

    #[Test]
    public function getRequiredProfilesWhenMalformed(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'profile' => [],
            ],
        );

        $this->expectException(QueryParamMalformed::class);

        $request->getRequiredProfiles();
    }

    #[Test]
    public function getRequiredProfilesWhenEmpty(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'profile' => '',
            ],
        );

        $profiles = $request->getRequiredProfiles();

        self::assertEmpty($profiles);
    }

    #[Test]
    public function getRequiredProfilesWhenTwoProfiles(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'profile' => 'https://example.com/profiles/last-modified https://example.com/profiles/created',
            ],
        );

        $profiles = $request->getRequiredProfiles();

        self::assertEquals(
            [
                'https://example.com/profiles/last-modified',
                'https://example.com/profiles/created',
            ],
            $profiles,
        );
    }

    #[Test]
    public function isProfileRequiredWhenTrue(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'profile' => 'https://example.com/profiles/last-modified https://example.com/profiles/created',
            ],
        );

        $isProfileRequired = $request->isProfileRequired('https://example.com/profiles/created');

        self::assertTrue($isProfileRequired);
    }

    #[Test]
    public function isProfileRequiredWhenFalse(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'profile' => 'https://example.com/profiles/last-modified https://example.com/profiles/created',
            ],
        );

        $isProfileRequired = $request->isProfileRequired('https://example.com/profiles/inexistent-profile');

        self::assertFalse($isProfileRequired);
    }

    #[Test]
    public function withHeaderInvalidatesParsedJsonApiHeaders(): void
    {
        $request = $this->createRequest()
            ->withHeader(
                'content-type',
                'application/vnd.api+json;profile=https://example.com/profiles/last-modified',
            )
            ->withHeader(
                'accept',
                'application/vnd.api+json;profile=https://example.com/profiles/last-modified',
            );

        $request->getAppliedProfiles();
        $request->getRequestedProfiles();

        $request = $request
            ->withHeader(
                'content-type',
                'application/vnd.api+json;profile=https://example.com/profiles/created',
            )
            ->withHeader(
                'accept',
                'application/vnd.api+json;profile=https://example.com/profiles/created',
            );

        self::assertEquals(['https://example.com/profiles/created'], $request->getAppliedProfiles());
        self::assertEquals(['https://example.com/profiles/created'], $request->getRequestedProfiles());
    }

    #[Test]
    public function withHeaderInvalidatesParsedExtensions(): void
    {
        $request = $this->createRequest()
            ->withHeader('content-type', 'application/vnd.api+json;ext=https://example.com/ext/a')
            ->withHeader('accept', 'application/vnd.api+json;ext=https://example.com/ext/a');

        $request->getAppliedExtensions();
        $request->getRequestedExtensions();

        $request = $request
            ->withHeader('content-type', 'application/vnd.api+json;ext=https://example.com/ext/b')
            ->withHeader('accept', 'application/vnd.api+json;ext=https://example.com/ext/b');

        self::assertEquals(['https://example.com/ext/b'], $request->getAppliedExtensions());
        self::assertEquals(['https://example.com/ext/b'], $request->getRequestedExtensions());
    }

    #[Test]
    public function getResourceWhenEmpty(): void
    {
        $request = $this->createRequestWithJsonBody([]);

        $resource = $request->getResource();

        self::assertNull($resource);
    }

    #[Test]
    public function getResource(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [],
            ],
        );

        $resource = $request->getResource();

        self::assertEquals([], $resource);
    }

    #[Test]
    public function getResourceTypeWhenEmpty(): void
    {
        $request = $this->createRequestWithJsonBody([]);

        $type = $request->getResourceType();

        self::assertNull($type);
    }

    #[Test]
    public function getResourceType(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'user',
                ],
            ],
        );

        $type = $request->getResourceType();

        self::assertEquals('user', $type);
    }

    #[Test]
    public function getResourceIdWhenEmpty(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [],
            ],
        );

        $id = $request->getResourceId();

        self::assertNull($id);
    }

    #[Test]
    public function getResourceId(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'id' => '1',
                ],
            ],
        );

        $id = $request->getResourceId();

        self::assertEquals('1', $id);
    }

    #[Test]
    public function getResourceLidWhenEmpty(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'user',
                    'id' => '1',
                ],
            ],
        );

        self::assertNull($request->getResourceLid());
    }

    #[Test]
    public function getResourceLid(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'user',
                    'lid' => 'local-1',
                ],
            ],
        );

        self::assertSame('local-1', $request->getResourceLid());
    }

    #[Test]
    public function getResourceAttributes(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'attributes' => [
                        'name' => 'Hot dog',
                    ],
                ],
            ],
        );

        $attributes = $request->getResourceAttributes();

        self::assertEquals(
            [
                'name' => 'Hot dog',
            ],
            $attributes,
        );
    }

    #[Test]
    public function getResourceAttribute(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'attributes' => [
                        'name' => 'Hot dog',
                    ],
                ],
            ],
        );

        $name = $request->getResourceAttribute('name');

        self::assertEquals('Hot dog', $name);
    }

    #[Test]
    public function hasToOneRelationshipWhenTrue(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                        'owner' => [
                            'data' => ['type' => 'human', 'id' => '1'],
                        ],
                    ],
                ],
            ],
        );

        $hasToOneRelationship = $request->hasToOneRelationship('owner');

        self::assertTrue($hasToOneRelationship);
    }

    #[Test]
    public function hasToOneRelationshipWhenFalse(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [],
                ],
            ],
        );

        $hasToOneRelationship = $request->hasToOneRelationship('owner');

        self::assertFalse($hasToOneRelationship);
    }

    #[Test]
    public function getToOneRelationship(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                        'owner' => [
                            'data' => ['type' => 'human', 'id' => '1'],
                        ],
                    ],
                ],
            ],
        );

        $resourceIdentifier = $request->getToOneRelationship('owner')->resourceIdentifier;
        $type = $resourceIdentifier !== null ? $resourceIdentifier->type : '';
        $id = $resourceIdentifier !== null ? $resourceIdentifier->id : '';

        self::assertEquals('human', $type);
        self::assertEquals('1', $id);
    }

    #[Test]
    public function getDeletingToOneRelationship(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                        'owner' => [
                            'data' => null,
                        ],
                    ],
                ],
            ],
        );

        $isEmpty = $request->getToOneRelationship('owner')->isEmpty();

        self::assertTrue($isEmpty);
    }

    #[Test]
    public function getToOneRelationshipWhenNotExists(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                    ],
                ],
            ],
        );

        $this->expectException(RelationshipNotExists::class);

        $request->getToOneRelationship('owner');
    }

    #[Test]
    public function hasToManyRelationshipWhenTrue(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                        'friends' => [
                            'data' => [
                                ['type' => 'dog', 'id' => '2'],
                                ['type' => 'dog', 'id' => '3'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $hasRelationship = $request->hasToManyRelationship('friends');

        self::assertTrue($hasRelationship);
    }

    #[Test]
    public function hasToManyRelationshipWhenFalse(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                    ],
                ],
            ],
        );

        $hasRelationship = $request->hasToManyRelationship('friends');

        self::assertFalse($hasRelationship);
    }

    #[Test]
    public function getToManyRelationship(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                        'friends' => [
                            'data' => [
                                ['type' => 'dog', 'id' => '2'],
                                ['type' => 'dog', 'id' => '3'],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $resourceIdentifiers = $request->getToManyRelationship('friends')->resourceIdentifiers;

        self::assertEquals('dog', $resourceIdentifiers[0]->type);
        self::assertEquals('2', $resourceIdentifiers[0]->id);
        self::assertEquals('dog', $resourceIdentifiers[1]->type);
        self::assertEquals('3', $resourceIdentifiers[1]->id);
    }

    #[Test]
    public function getToManyRelationshipWhenNotExists(): void
    {
        $request = $this->createRequestWithJsonBody(
            [
                'data' => [
                    'type' => 'dog',
                    'id' => '1',
                    'relationships' => [
                    ],
                ],
            ],
        );

        $this->expectException(RelationshipNotExists::class);

        $request->getToManyRelationship('friends');
    }

    #[Test]
    public function withQueryParamsInvalidatesParsedJsonApiQueryParams(): void
    {
        $request = $this->createRequestWithQueryParams(
            [
                'fields' => ['book' => 'title,pages'],
                'include' => 'authors',
                'page' => ['offset' => 0, 'limit' => 10],
                'filter' => ['title' => 'Working Effectively with Unit Tests'],
                'sort' => 'title',
                'profile' => 'https://example.com/profiles/last-modified',
            ],
        );

        $request->getIncludedFields('');
        $request->getIncludedRelationships('');
        $request->getPagination();
        $request->getFiltering();
        $request->getSorting();
        $request->getRequiredProfiles();

        $request = $request->withQueryParams(
            [
                'fields' => ['book' => 'isbn'],
                'include' => 'publisher',
                'page' => ['number' => 1, 'size' => 10],
                'filter' => ['title' => 'Building Microservices'],
                'sort' => 'isbn',
                'profile' => 'https://example.com/profiles/created',
            ],
        );

        self::assertEquals(['isbn'], $request->getIncludedFields('book'));
        self::assertEquals(['publisher'], $request->getIncludedRelationships(''));
        self::assertEquals(['number' => 1, 'size' => 10], $request->getPagination());
        self::assertEquals(['title' => 'Building Microservices'], $request->getFiltering());
        self::assertEquals(['isbn'], $request->getSorting());
        self::assertEquals(['https://example.com/profiles/created'], $request->getRequiredProfiles());
    }

    private function createRequest(): JsonApiRequest
    {
        return new JsonApiRequest(new ServerRequest('GET', '/'));
    }

    /** @param array<string, mixed> $body */
    private function createRequestWithJsonBody(array $body): JsonApiRequest
    {
        $psrRequest = (new ServerRequest('GET', '/'))->withParsedBody($body);

        return new JsonApiRequest($psrRequest);
    }

    private function createRequestWithHeader(string $headerName, string $headerValue): JsonApiRequest
    {
        $psrRequest = new ServerRequest('GET', '/', [$headerName => $headerValue]);

        return new JsonApiRequest($psrRequest);
    }

    /** @param array<string, mixed> $queryParams */
    private function createRequestWithQueryParams(array $queryParams): JsonApiRequest
    {
        $psrRequest = (new ServerRequest('GET', '/'))->withQueryParams($queryParams);

        return new JsonApiRequest($psrRequest);
    }
}
