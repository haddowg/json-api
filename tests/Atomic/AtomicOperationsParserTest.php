<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Atomic;

use haddowg\JsonApi\Atomic\AtomicOperationCode;
use haddowg\JsonApi\Atomic\AtomicOperationsParser;
use haddowg\JsonApi\Exception\AtomicOperationsInvalid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('spec:extensions-and-profiles')]
final class AtomicOperationsParserTest extends TestCase
{
    #[Test]
    public function parsesAValidBatchToOrderedDescriptors(): void
    {
        $document = [
            'atomic:operations' => [
                [
                    'op' => 'add',
                    'data' => ['type' => 'articles', 'lid' => 'a1', 'attributes' => ['title' => 'JSON:API']],
                ],
                [
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                    'data' => ['type' => 'articles', 'id' => '1', 'attributes' => ['title' => 'Updated']],
                ],
                [
                    'op' => 'remove',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                ],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        self::assertCount(3, $descriptors);

        self::assertSame(AtomicOperationCode::Add, $descriptors[0]->opCode);
        self::assertNull($descriptors[0]->ref);
        self::assertNull($descriptors[0]->href);
        self::assertSame(0, $descriptors[0]->index);
        self::assertSame(['type' => 'articles', 'lid' => 'a1', 'attributes' => ['title' => 'JSON:API']], $descriptors[0]->data);

        self::assertSame(AtomicOperationCode::Update, $descriptors[1]->opCode);
        self::assertNotNull($descriptors[1]->ref);
        self::assertSame('articles', $descriptors[1]->ref->type);
        self::assertSame('1', $descriptors[1]->ref->id);
        self::assertSame(1, $descriptors[1]->index);

        self::assertSame(AtomicOperationCode::Remove, $descriptors[2]->opCode);
        self::assertNotNull($descriptors[2]->ref);
        self::assertNull($descriptors[2]->data);
        self::assertSame(2, $descriptors[2]->index);
    }

    #[Test]
    public function parsesARefWithALidAndARelationship(): void
    {
        $document = [
            'atomic:operations' => [
                [
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'lid' => 'a1', 'relationship' => 'author'],
                    'data' => ['type' => 'people', 'id' => '9'],
                ],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        $ref = $descriptors[0]->ref;
        self::assertNotNull($ref);
        self::assertSame('articles', $ref->type);
        self::assertNull($ref->id);
        self::assertSame('a1', $ref->lid);
        self::assertTrue($ref->hasLid());
        self::assertSame('author', $ref->relationship);
        self::assertTrue($ref->hasRelationship());
    }

    #[Test]
    public function parsesAnHrefTargetedOperation(): void
    {
        $document = [
            'atomic:operations' => [
                [
                    'op' => 'add',
                    'href' => '/articles',
                    'data' => ['type' => 'articles', 'attributes' => ['title' => 'Hi']],
                ],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        self::assertNull($descriptors[0]->ref);
        self::assertSame('/articles', $descriptors[0]->href);
        self::assertFalse($descriptors[0]->hasRef());
    }

    #[Test]
    public function aToManyRelationshipDataMayBeAListOfIdentifiers(): void
    {
        $document = [
            'atomic:operations' => [
                [
                    'op' => 'add',
                    'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'tags'],
                    'data' => [
                        ['type' => 'tags', 'id' => '4'],
                        ['type' => 'tags', 'id' => '5'],
                    ],
                ],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        self::assertSame(
            [['type' => 'tags', 'id' => '4'], ['type' => 'tags', 'id' => '5']],
            $descriptors[0]->data,
        );
    }

    #[Test]
    public function aToOneRelationshipUpdateMayCarryNullData(): void
    {
        $document = [
            'atomic:operations' => [
                [
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => 'author'],
                    'data' => null,
                ],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        self::assertNull($descriptors[0]->data);
    }

    #[Test]
    public function rejectsADocumentThatIsNotAnObject(): void
    {
        $this->assertParseError([], '');
    }

    #[Test]
    public function rejectsADocumentMissingTheOperationsMember(): void
    {
        $this->assertParseError(['meta' => []], '');
    }

    #[Test]
    public function rejectsAnOperationsMemberThatIsNotAnArray(): void
    {
        $this->assertParseError(['atomic:operations' => 'nope'], '/atomic:operations');
    }

    #[Test]
    public function rejectsAnEmptyOperationsArray(): void
    {
        $this->assertParseError(['atomic:operations' => []], '/atomic:operations');
    }

    #[Test]
    public function rejectsAnOperationThatIsNotAnObject(): void
    {
        $this->assertParseError(['atomic:operations' => ['nope']], '/atomic:operations/0');
    }

    #[Test]
    public function rejectsAMissingOp(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['ref' => ['type' => 'articles', 'id' => '1']]]],
            '/atomic:operations/0/op',
        );
    }

    #[Test]
    public function rejectsANonStringOp(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 42, 'ref' => ['type' => 'articles', 'id' => '1']]]],
            '/atomic:operations/0/op',
        );
    }

    #[Test]
    public function rejectsAnUnknownOpCode(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'merge', 'ref' => ['type' => 'articles', 'id' => '1']]]],
            '/atomic:operations/0/op',
        );
    }

    #[Test]
    public function aResourceAddMayOmitBothRefAndHref(): void
    {
        $document = [
            'atomic:operations' => [
                ['op' => 'add', 'data' => ['type' => 'articles', 'attributes' => ['title' => 'Hi']]],
            ],
        ];

        $descriptors = (new AtomicOperationsParser())->parse($document);

        self::assertNull($descriptors[0]->ref);
        self::assertNull($descriptors[0]->href);
        self::assertSame(AtomicOperationCode::Add, $descriptors[0]->opCode);
    }

    #[Test]
    public function rejectsAnUpdateWithNeitherRefNorHref(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'update', 'data' => ['type' => 'articles', 'id' => '1']]]],
            '/atomic:operations/0',
        );
    }

    #[Test]
    public function rejectsARemoveWithNeitherRefNorHref(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'remove']]],
            '/atomic:operations/0',
        );
    }

    #[Test]
    public function rejectsAnOperationWithBothRefAndHref(): void
    {
        $this->assertParseError(
            [
                'atomic:operations' => [[
                    'op' => 'update',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                    'href' => '/articles/1',
                    'data' => ['type' => 'articles', 'id' => '1'],
                ]],
            ],
            '/atomic:operations/0',
        );
    }

    #[Test]
    public function rejectsAnEmptyHref(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'add', 'href' => '', 'data' => ['type' => 'articles']]]],
            '/atomic:operations/0/href',
        );
    }

    #[Test]
    public function rejectsARefThatIsNotAnObject(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'remove', 'ref' => 'articles/1']]],
            '/atomic:operations/0/ref',
        );
    }

    #[Test]
    public function rejectsARefWithoutAType(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'remove', 'ref' => ['id' => '1']]]],
            '/atomic:operations/0/ref/type',
        );
    }

    #[Test]
    public function rejectsARefCarryingBothIdAndLid(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => '1', 'lid' => 'a1']]]],
            '/atomic:operations/0/ref',
        );
    }

    #[Test]
    public function rejectsARefWithNeitherIdNorLid(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'update', 'ref' => ['type' => 'articles', 'relationship' => 'author'], 'data' => null]]],
            '/atomic:operations/0/ref',
        );
    }

    #[Test]
    public function rejectsANonStringRefMember(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'remove', 'ref' => ['type' => 'articles', 'id' => 42]]]],
            '/atomic:operations/0/ref/id',
        );
    }

    #[Test]
    public function rejectsAnEmptyStringRefMember(): void
    {
        // An empty `relationship` must not silently demote a relationship operation
        // to a resource operation — it is rejected, not coerced to absent.
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'update', 'ref' => ['type' => 'articles', 'id' => '1', 'relationship' => ''], 'data' => null]]],
            '/atomic:operations/0/ref/relationship',
        );
    }

    #[Test]
    public function rejectsAResourceRemoveCarryingData(): void
    {
        $this->assertParseError(
            [
                'atomic:operations' => [[
                    'op' => 'remove',
                    'ref' => ['type' => 'articles', 'id' => '1'],
                    'data' => ['type' => 'articles', 'id' => '1'],
                ]],
            ],
            '/atomic:operations/0/data',
        );
    }

    #[Test]
    public function rejectsANonRemoveOperationMissingData(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'add', 'ref' => ['type' => 'articles', 'id' => '1']]]],
            '/atomic:operations/0/data',
        );
    }

    #[Test]
    public function rejectsAScalarData(): void
    {
        $this->assertParseError(
            ['atomic:operations' => [['op' => 'add', 'href' => '/articles', 'data' => 'nope']]],
            '/atomic:operations/0/data',
        );
    }

    /**
     * Asserts parsing `$document` throws {@see AtomicOperationsInvalid} whose single
     * error carries the expected `source.pointer`.
     */
    private function assertParseError(mixed $document, string $expectedPointer): void
    {
        try {
            (new AtomicOperationsParser())->parse($document);
        } catch (AtomicOperationsInvalid $exception) {
            self::assertSame(400, $exception->getStatusCode());

            $errors = $exception->getErrors();
            self::assertCount(1, $errors);
            self::assertNotNull($errors[0]->source);
            self::assertSame($expectedPointer, $errors[0]->source->pointer);

            return;
        }

        self::fail('Expected AtomicOperationsInvalid to be thrown.');
    }
}
