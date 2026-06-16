<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Negotiation;

use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Negotiation\StrictQueryParameterValidator;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrictQueryParameterValidator::class)]
#[Group('spec:fetching-data')]
final class StrictQueryParameterValidatorTest extends TestCase
{
    #[Test]
    public function emptyQueryStringPasses(): void
    {
        $validator = new StrictQueryParameterValidator();

        $validator->validate(StubJsonApiRequest::create([]));

        self::addToAssertionCount(1);
    }

    #[Test]
    public function everyReservedFamilyPasses(): void
    {
        $validator = new StrictQueryParameterValidator();

        $validator->validate(StubJsonApiRequest::create([
            'fields' => ['user' => 'name'],
            'include' => 'author',
            'sort' => '-name',
            'page' => ['number' => '1'],
            'filter' => ['age' => '21'],
            'profile' => '',
        ]));

        self::addToAssertionCount(1);
    }

    #[Test]
    public function anUnrecognizedAllLowercaseBaseIsRejected(): void
    {
        $validator = new StrictQueryParameterValidator();

        $this->expectException(QueryParamUnrecognized::class);
        $this->expectExceptionMessage("Query parameter 'paginate' can't be recognized!");

        $validator->validate(StubJsonApiRequest::create(['paginate' => '-name']));
    }

    #[Test]
    public function aWellNamedCustomBaseTheServerDoesNotRecognizeIsRejected(): void
    {
        // The spec-baseline validator tolerates a well-named custom family (it
        // carries an uppercase letter); strict mode rejects the unrecognized one.
        $validator = new StrictQueryParameterValidator();

        $this->expectException(QueryParamUnrecognized::class);

        $validator->validate(StubJsonApiRequest::create(['relatedQuery' => ['author' => ['sort' => 'name']]]));
    }

    #[Test]
    public function aMisspelledReservedFamilyIsRejected(): void
    {
        // ?pag[number]=2 parses to the base name `pag`, not the reserved `page`.
        $validator = new StrictQueryParameterValidator();

        $this->expectException(QueryParamUnrecognized::class);
        $this->expectExceptionMessage("Query parameter 'pag' can't be recognized!");

        $validator->validate(StubJsonApiRequest::create(['pag' => ['number' => '2']]));
    }

    #[Test]
    public function aRegisteredCustomFamilyPasses(): void
    {
        $validator = new StrictQueryParameterValidator(['withCount', 'relatedQuery', 'rQ']);

        $validator->validate(StubJsonApiRequest::create([
            'withCount' => 'comments',
            'relatedQuery' => ['author' => ['sort' => 'name']],
            'rQ' => ['author' => ['sort' => 'name']],
        ]));

        self::addToAssertionCount(1);
    }

    #[Test]
    public function theFirstUnrecognizedBaseIsReported(): void
    {
        $validator = new StrictQueryParameterValidator();

        try {
            $validator->validate(StubJsonApiRequest::create(['bogus' => '1']));
            self::fail('Expected the unrecognized base to be rejected.');
        } catch (QueryParamUnrecognized $e) {
            self::assertSame('bogus', $e->unrecognizedQueryParam);
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('bogus', $e->getErrors()[0]->source?->parameter);
        }
    }
}
