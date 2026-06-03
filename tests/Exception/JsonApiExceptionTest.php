<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Exception\ApplicationError;
use haddowg\JsonApi\Exception\ClientGeneratedIdAlreadyExists;
use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\ClientGeneratedIdRequired;
use haddowg\JsonApi\Exception\DataMemberMissing;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\InclusionUnsupported;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamMalformed;
use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Exception\RelationshipNotExists;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RemovalProhibited;
use haddowg\JsonApi\Exception\RequestBodyInvalidJson;
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\RequiredTopLevelMembersMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierIdMissing;
use haddowg\JsonApi\Exception\ResourceIdentifierLidInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeInvalid;
use haddowg\JsonApi\Exception\ResourceIdentifierTypeMissing;
use haddowg\JsonApi\Exception\ResourceIdInvalid;
use haddowg\JsonApi\Exception\ResourceIdMissing;
use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Exception\ResourceTypeMissing;
use haddowg\JsonApi\Exception\ResourceTypeUnacceptable;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJson;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Exception\TopLevelMemberNotAllowed;
use haddowg\JsonApi\Exception\TopLevelMembersIncompatible;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class JsonApiExceptionTest extends TestCase
{
    /**
     * Every concrete exception: HTTP status code, and the first error's
     * status/code/title, asserted against their expected payloads.
     *
     * @return iterable<string, array{AbstractJsonApiException, int, string, string, string}>
     */
    public static function exceptionProvider(): iterable
    {
        yield 'ApplicationError' => [new ApplicationError(), 500, '500', 'APPLICATION_ERROR', 'Application error'];
        yield 'ClientGeneratedIdAlreadyExists' => [new ClientGeneratedIdAlreadyExists('1'), 409, '409', 'CLIENT_GENERATED_ID_ALREADY_EXISTS', 'Client generated ID already exists'];
        yield 'ClientGeneratedIdNotSupported' => [new ClientGeneratedIdNotSupported('1'), 403, '403', 'CLIENT_GENERATED_ID_NOT_SUPPORTED', 'Client generated ID is not supported'];
        yield 'ClientGeneratedIdRequired' => [new ClientGeneratedIdRequired(), 403, '403', 'CLIENT_GENERATED_ID_REQUIRED', 'Required client generated ID'];
        yield 'DataMemberMissing' => [new DataMemberMissing(), 400, '400', 'DATA_MEMBER_MISSING', "Missing `data` member at the document's top level"];
        yield 'FullReplacementProhibited' => [new FullReplacementProhibited('rel'), 403, '403', 'FULL_REPLACEMENT_PROHIBITED', 'Full replacement is prohibited'];
        yield 'InclusionUnrecognized' => [new InclusionUnrecognized(['a']), 400, '400', 'INCLUSION_UNRECOGNIZED', 'Inclusion is unrecognized'];
        yield 'InclusionUnsupported' => [new InclusionUnsupported(), 400, '400', 'INCLUSION_UNSUPPORTED', 'Inclusion is unsupported'];
        yield 'MediaTypeUnacceptable' => [new MediaTypeUnacceptable('text/html'), 406, '406', 'MEDIA_TYPE_UNACCEPTABLE', 'The provided media type is unacceptable'];
        yield 'MediaTypeUnsupported' => [new MediaTypeUnsupported('text/html'), 415, '415', 'MEDIA_TYPE_UNSUPPORTED', 'The provided media type is unsupported'];
        yield 'QueryParamMalformed' => [new QueryParamMalformed('sort', 'x'), 400, '400', 'QUERY_PARAM_MALFORMED', 'Query parameter is malformed'];
        yield 'QueryParamUnrecognized' => [new QueryParamUnrecognized('foo'), 400, '400', 'QUERY_PARAM_UNRECOGNIZED', 'Query parameter is unrecognized'];
        yield 'RelationshipNotExists' => [new RelationshipNotExists('rel'), 404, '404', 'RELATIONSHIP_NOT_EXISTS', 'The requested relationship does not exist!'];
        yield 'RelationshipTypeInappropriate' => [new RelationshipTypeInappropriate('rel', 'a', 'b'), 400, '400', 'RELATIONSHIP_TYPE_INAPPROPRIATE', 'Relationship type is inappropriate'];
        yield 'RemovalProhibited' => [new RemovalProhibited('rel'), 403, '403', 'REMOVAL_PROHIBITED', 'Removal is prohibited'];
        yield 'RequestBodyInvalidJson' => [new RequestBodyInvalidJson('lint'), 400, '400', 'REQUEST_BODY_INVALID_JSON', 'Request body is an invalid JSON document'];
        yield 'RequestBodyInvalidJsonApi' => [new RequestBodyInvalidJsonApi([['message' => 'abc']]), 400, '400', 'REQUEST_BODY_INVALID_JSON_API', 'Request body is an invalid JSON:API document'];
        yield 'RequiredTopLevelMembersMissing' => [new RequiredTopLevelMembersMissing(), 400, '400', 'REQUIRED_TOP_LEVEL_MEMBERS_MISSING', 'Required top-level members are missing'];
        yield 'ResourceIdInvalid' => [new ResourceIdInvalid('integer'), 400, '400', 'RESOURCE_ID_INVALID', 'Resource ID is invalid'];
        yield 'ResourceIdMissing' => [new ResourceIdMissing(), 400, '400', 'RESOURCE_ID_MISSING', 'Resource ID is missing'];
        yield 'ResourceIdentifierIdInvalid' => [new ResourceIdentifierIdInvalid('integer'), 400, '400', 'RESOURCE_IDENTIFIER_ID_INVALID', 'Resource identifier ID is invalid'];
        yield 'ResourceIdentifierIdMissing' => [new ResourceIdentifierIdMissing([]), 400, '400', 'RESOURCE_IDENTIFIER_ID_MISSING', 'An ID for the resource identifier is missing'];
        yield 'ResourceIdentifierLidInvalid' => [new ResourceIdentifierLidInvalid('integer'), 400, '400', 'RESOURCE_IDENTIFIER_LID_INVALID', 'Resource identifier local ID is invalid'];
        yield 'ResourceIdentifierTypeInvalid' => [new ResourceIdentifierTypeInvalid('integer'), 400, '400', 'RESOURCE_IDENTIFIER_TYPE_INVALID', 'Resource identifier type is invalid'];
        yield 'ResourceIdentifierTypeMissing' => [new ResourceIdentifierTypeMissing([]), 400, '400', 'RESOURCE_IDENTIFIER_TYPE_MISSING', 'A type for the resource identifier is missing'];
        yield 'ResourceNotFound' => [new ResourceNotFound(), 404, '404', 'RESOURCE_NOT_FOUND', 'Resource not found'];
        yield 'ResourceTypeMissing' => [new ResourceTypeMissing(), 400, '400', 'RESOURCE_TYPE_MISSING', 'Resource type is missing'];
        yield 'ResourceTypeUnacceptable' => [new ResourceTypeUnacceptable('book', []), 409, '409', 'RESOURCE_TYPE_UNACCEPTABLE', 'Resource type is unacceptable'];
        yield 'ResponseBodyInvalidJson' => [new ResponseBodyInvalidJson('lint'), 500, '500', 'RESPONSE_BODY_INVALID_JSON', 'Response body is an invalid JSON document'];
        yield 'ResponseBodyInvalidJsonApi' => [new ResponseBodyInvalidJsonApi([['message' => 'abc']]), 500, '500', 'RESPONSE_BODY_INVALID_JSON_API', 'Response body is an invalid JSON:API document'];
        yield 'SortParamUnrecognized' => [new SortParamUnrecognized('foo'), 400, '400', 'SORTING_UNRECOGNIZED', 'Sorting paramter is unrecognized'];
        yield 'SortingUnsupported' => [new SortingUnsupported(), 400, '400', 'SORTING_UNSUPPORTED', 'Sorting is unsupported'];
        yield 'TopLevelMemberNotAllowed' => [new TopLevelMemberNotAllowed(), 400, '400', 'TOP_LEVEL_MEMBER_NOT_ALLOWED', 'Top-level member is not allowed'];
        yield 'TopLevelMembersIncompatible' => [new TopLevelMembersIncompatible(), 400, '400', 'TOP_LEVEL_MEMBERS_INCOMPATIBLE', 'Top-level members are incompatible'];
    }

    #[Test]
    #[DataProvider('exceptionProvider')]
    public function eachExceptionExposesItsErrorDataAndStatus(
        AbstractJsonApiException $exception,
        int $statusCode,
        string $errorStatus,
        string $errorCode,
        string $errorTitle,
    ): void {
        self::assertInstanceOf(\haddowg\JsonApi\Exception\JsonApiExceptionInterface::class, $exception);
        self::assertSame($statusCode, $exception->getStatusCode());

        $errors = $exception->getErrors();
        self::assertNotEmpty($errors);

        $error = $errors[0];
        self::assertSame($errorStatus, $error->status);
        self::assertSame($errorCode, $error->code);
        self::assertSame($errorTitle, $error->title);
        self::assertNotSame('', $error->detail);
    }

    #[Test]
    public function statusCodeMatchesTheHttpStatusPassedUpTheConstructor(): void
    {
        $exception = new ResourceNotFound();

        self::assertSame(404, $exception->getStatusCode());
        self::assertSame(404, $exception->getCode());
    }
}
