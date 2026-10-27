<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Tests\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Link\ActionLink;
use haddowg\JsonApi\Resource\Link\ActionLinkBuilder;
use haddowg\JsonApi\Resource\Link\DeclaredLink;
use haddowg\JsonApi\Resource\Link\DeclaredLinkBuilder;
use haddowg\JsonApi\Tests\Double\StubJsonApiRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionLink::class)]
#[CoversClass(ActionLinkBuilder::class)]
#[CoversClass(AbstractResource::class)]
final class ActionLinkTest extends TestCase
{
    #[Test]
    public function theBuilderCarriesThePathAndDescription(): void
    {
        $link = ActionLink::for('complete')->describedAs('Present while the upload is pending.')->build();

        self::assertSame('complete', $link->path);
        self::assertSame('Present while the upload is pending.', $link->description);
    }

    #[Test]
    public function aLinkWithNoConditionAlwaysApplies(): void
    {
        self::assertTrue(ActionLink::for('complete')->build()->appliesTo(null, new StubJsonApiRequest()));
    }

    #[Test]
    public function theConditionReceivesTheModelAndRequest(): void
    {
        $request = new StubJsonApiRequest();
        $link = ActionLink::for('complete')
            ->when(static fn(ActionLinkFile $file, JsonApiRequestInterface $r): bool => $file->pending && $r === $request)
            ->build();

        self::assertTrue($link->appliesTo(new ActionLinkFile(true), $request));
        self::assertFalse($link->appliesTo(new ActionLinkFile(false), $request));
        self::assertFalse($link->appliesTo(new ActionLinkFile(true), new StubJsonApiRequest()));
    }

    #[Test]
    public function anActionLinkNeedsAPath(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('An action link needs the path of the action it links to.');

        ActionLink::for('')->build();
    }

    #[Test]
    public function aResourceSeparatesItsActionLinksFromItsDeclaredLinks(): void
    {
        $resource = new ActionLinkResource([
            DeclaredLink::make('thumbnail')->hrefUsing(self::href()),
            ActionLink::for('complete'),
            ActionLink::for('archive')->build(),
        ]);

        self::assertSame(['thumbnail'], \array_map(static fn(DeclaredLink $link): string => $link->name, $resource->allLinks()));
        $actionLinks = $resource->allActionLinks();
        self::assertSame(['complete', 'archive'], \array_map(static fn(ActionLink $link): string => $link->path, $actionLinks));
        self::assertSame($actionLinks, $resource->allActionLinks());
    }

    #[Test]
    public function twoActionLinksForOnePathFailNamingTheResource(): void
    {
        $resource = new ActionLinkResource([ActionLink::for('complete'), ActionLink::for('complete')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Resource "%s" declares the action link "complete" twice.', ActionLinkResource::class));

        $resource->allActionLinks();
    }

    #[Test]
    public function aDeclaredLinkNamedLikeAnActionLinkFailsNamingTheResource(): void
    {
        $resource = new ActionLinkResource([
            ActionLink::for('complete'),
            DeclaredLink::make('complete')->hrefUsing(self::href()),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('Resource "%s" declares the link "complete" and an action link for the "complete" action', ActionLinkResource::class));

        $resource->allLinks();
    }

    /**
     * @return \Closure(mixed, JsonApiRequestInterface): ?string
     */
    private static function href(): \Closure
    {
        return static fn(mixed $model, JsonApiRequestInterface $request): ?string => null;
    }
}

final readonly class ActionLinkFile
{
    public function __construct(public bool $pending) {}
}

final class ActionLinkResource extends AbstractResource
{
    public static string $type = 'files';

    /**
     * @param list<DeclaredLink|DeclaredLinkBuilder|ActionLink|ActionLinkBuilder> $declared
     */
    public function __construct(private readonly array $declared) {}

    public function fields(): array
    {
        return [Id::make()];
    }

    public function links(): array
    {
        return $this->declared;
    }
}
