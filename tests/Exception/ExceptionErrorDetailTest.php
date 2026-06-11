<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Exception;

use haddowg\JsonApi\Exception\AdditionProhibited;
use haddowg\JsonApi\Exception\ClientGeneratedIdNotSupported;
use haddowg\JsonApi\Exception\FullReplacementProhibited;
use haddowg\JsonApi\Exception\InclusionUnrecognized;
use haddowg\JsonApi\Exception\MediaTypeUnacceptable;
use haddowg\JsonApi\Exception\MediaTypeUnsupported;
use haddowg\JsonApi\Exception\QueryParamMalformed;
use haddowg\JsonApi\Exception\RelationshipTypeInappropriate;
use haddowg\JsonApi\Exception\RequestBodyInvalidJson;
use haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\ResponseBodyInvalidJsonApi;
use haddowg\JsonApi\Exception\SortingUnsupported;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:errors')]
final class ExceptionErrorDetailTest extends TestCase
{
    #[Test]
    public function fullReplacementProhibitedPointsAtTheRelationship(): void
    {
        $error = (new FullReplacementProhibited('author'))->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('/data/relationships/author', $error->source->pointer);
        self::assertSame("Full replacement of relationship 'author' is prohibited!", $error->detail);
    }

    #[Test]
    public function additionProhibitedPointsAtTheRelationship(): void
    {
        $error = (new AdditionProhibited('tags'))->getErrors()[0];

        self::assertSame('403', $error->status);
        self::assertSame('ADDITION_PROHIBITED', $error->code);
        self::assertSame('Addition is prohibited', $error->title);
        self::assertNotNull($error->source);
        self::assertSame('/data/relationships/tags', $error->source->pointer);
        self::assertSame("Addition to relationship 'tags' is prohibited!", $error->detail);
    }

    #[Test]
    public function relationshipTypeInappropriatePointsAtTheRelationship(): void
    {
        $error = (new RelationshipTypeInappropriate('author', 'to-one', 'to-many'))->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('/data/relationships/author', $error->source->pointer);
        self::assertStringContainsString('to-many is expected', $error->detail);
    }

    #[Test]
    public function relationshipTypeInappropriateFallsBackWhenNoExpectedType(): void
    {
        $exception = new RelationshipTypeInappropriate('author', 'to-one', '');

        self::assertStringContainsString('it is not the one which is expected', $exception->getMessage());
    }

    #[Test]
    public function clientGeneratedIdNotSupportedOmitsTheIdWhenEmpty(): void
    {
        self::assertSame(
            'Client generated ID is not supported!',
            (new ClientGeneratedIdNotSupported(''))->getMessage(),
        );
        self::assertSame(
            "Client generated ID '7' is not supported!",
            (new ClientGeneratedIdNotSupported('7'))->getMessage(),
        );
    }

    #[Test]
    public function queryParamMalformedPointsAtTheParameterAndRetainsValue(): void
    {
        $exception = new QueryParamMalformed('filter', ['x' => 1]);
        $error = $exception->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('filter', $error->source->parameter);
        self::assertSame(['x' => 1], $exception->malformedQueryParamValue);
    }

    #[Test]
    public function inclusionUnrecognizedListsThePaths(): void
    {
        $error = (new InclusionUnrecognized(['a', 'b', 'c']))->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('include', $error->source->parameter);
        self::assertStringContainsString("'a, b, c'", $error->detail);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function mediaTypeUnacceptablePointsAtTheAcceptParameter(): void
    {
        $error = (new MediaTypeUnacceptable('text/html'))->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('accept', $error->source->parameter);
    }

    #[Test]
    #[Group('spec:content-negotiation')]
    public function mediaTypeUnsupportedPointsAtTheContentTypeParameter(): void
    {
        $error = (new MediaTypeUnsupported('text/html'))->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('content-type', $error->source->parameter);
    }

    #[Test]
    public function sortingUnsupportedPointsAtTheSortParameter(): void
    {
        $error = (new SortingUnsupported())->getErrors()[0];

        self::assertNotNull($error->source);
        self::assertSame('sort', $error->source->parameter);
    }

    #[Test]
    public function requestBodyInvalidJsonAttachesOriginalBodyMetaWhenRequested(): void
    {
        $without = (new RequestBodyInvalidJson('lint'))->getErrors()[0];
        self::assertSame([], $without->meta);

        $with = (new RequestBodyInvalidJson('lint', 'abc'))->getErrors()[0];
        self::assertSame(['original' => 'abc'], $with->meta);
    }

    #[Test]
    public function requestBodyInvalidJsonApiYieldsOneErrorPerValidationErrorWithSources(): void
    {
        $exception = new RequestBodyInvalidJsonApi([
            ['message' => 'abc', 'property' => 'property1'],
            ['message' => 'cde', 'property' => ''],
        ]);

        $errors = $exception->getErrors();

        self::assertCount(2, $errors);
        self::assertSame('400', $errors[0]->status);
        self::assertSame('Abc', $errors[0]->detail);
        self::assertNotNull($errors[0]->source);
        self::assertSame('property1', $errors[0]->source->pointer);
        self::assertSame('Cde', $errors[1]->detail);
        self::assertNull($errors[1]->source);
    }

    #[Test]
    public function requestBodyInvalidJsonApiAttachesOriginalBodyToFirstErrorWhenRequested(): void
    {
        $exception = new RequestBodyInvalidJsonApi(
            [['message' => 'abc'], ['message' => 'def']],
            originalBody: ['a' => 'b'],
            includeOriginalBody: true,
        );

        $errors = $exception->getErrors();

        self::assertSame(['original' => ['a' => 'b']], $errors[0]->meta);
        self::assertSame([], $errors[1]->meta);
    }

    #[Test]
    public function responseBodyInvalidJsonApiUsesServerErrorStatus(): void
    {
        $errors = (new ResponseBodyInvalidJsonApi([
            ['message' => 'abc', 'property' => 'property1'],
            ['message' => 'cde', 'property' => ''],
        ]))->getErrors();

        self::assertCount(2, $errors);
        self::assertSame('500', $errors[0]->status);
        self::assertSame('Abc', $errors[0]->detail);
        self::assertNotNull($errors[0]->source);
        self::assertSame('property1', $errors[0]->source->pointer);
        self::assertNull($errors[1]->source);
    }
}
