<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Skill;

use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusA2a\Skill\ProductSearchSkill;
use Koh\SyliusA2a\Skill\SkillInput;
use Koh\SyliusA2a\Skill\SkillResult;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only what an anonymous storefront visitor finds, within bounds a public
 * endpoint can afford.
 */
#[CoversClass(ProductSearchSkill::class)]
#[CoversClass(SkillResult::class)]
#[CoversClass(SkillInput::class)]
final class ProductSearchSkillTest extends TestCase
{
    public function testItFindsOnlyEnabledPricedProductsOfTheChannelByName(): void
    {
        $result = $this->search('robusto');

        // Disabled, foreign-channel and unpriced Robustos are not for sale here.
        self::assertSame(['ROBUSTO-1'], array_column($result->data['products'] ?? [], 'code'));
        self::assertSame('Found 1 product for "robusto".', $result->summary);
        self::assertSame('product-search-results', $result->artifactName);
        self::assertSame('robusto', $result->data['query'] ?? null);
        self::assertSame('de_DE', $result->data['locale'] ?? null);
        self::assertFalse($result->data['hasMore'] ?? null);
        self::assertArrayNotHasKey('nextOffset', $result->data ?? []);
        self::assertArrayNotHasKey('matchedTerms', $result->data ?? []);
    }

    public function testThePlainTextRenderingListsOneNumberedLinePerProduct(): void
    {
        $result = $this->search('c');

        self::assertSame(
            "1. Cigar Cutter — 19,99\u{a0}€ — in stock — https://shop.example.com/de_DE/products/cigar-cutter\n"
            ."2. Corona Clasica — 9,00\u{a0}€ — out of stock — https://shop.example.com/de_DE/products/corona-clasica\n"
            ."3. Robusto Especial — from 12,50\u{a0}€ — in stock — https://shop.example.com/de_DE/products/robusto-especial",
            $result->text,
        );
        self::assertSame('Found 3 products for "c".', $result->summary);
    }

    public function testAPageEndsWhereTheLimitSaysAndTellsWhereTheNextStarts(): void
    {
        $result = $this->search('c', limit: 2);

        self::assertCount(2, $result->data['products'] ?? []);
        self::assertTrue($result->data['hasMore'] ?? null);
        self::assertSame(2, $result->data['nextOffset'] ?? null);
        self::assertSame('Found 2 products for "c"; more are available with a higher offset.', $result->summary);

        $next = $this->search('c', limit: 2, offset: 2);
        self::assertSame(['ROBUSTO-1'], array_column($next->data['products'] ?? [], 'code'));
        self::assertFalse($next->data['hasMore'] ?? null);
    }

    public function testPagingStopsAtTheWindow(): void
    {
        $catalogue = new Catalogue();
        for ($i = 0; $i < 3; ++$i) {
            $catalogue->add('P'.$i, ['de_DE' => 'Pipe '.$i], [['code' => 'P'.$i, 'price' => 100]]);
        }
        $skill = new ProductSearchSkill($catalogue, $catalogue->presenter());

        // An offset of 199 leaves room for exactly one result, and none after it.
        $result = $skill->run(['query' => 'pipe', 'limit' => 25, 'offset' => 199], $catalogue->context(), 'de_DE');

        self::assertFalse($result->data['hasMore'] ?? null);
        self::assertSame([], $result->data['products'] ?? null);

        $last = $skill->run(['query' => 'pipe', 'limit' => 25, 'offset' => 0], $catalogue->context(), 'de_DE');
        self::assertCount(3, $last->data['products'] ?? []);
    }

    public function testTheLastPageOfTheWindowHasNoNextPageEvenWhenMoreWouldMatch(): void
    {
        $catalogue = Catalogue::shop();
        $robusto = $catalogue->products[0];
        // A source that always has more: what a broad term in a big catalogue looks like.
        $endless = new class($robusto) implements \Koh\SyliusA2a\Catalog\ProductSource {
            /** @var list<array{int, int}> */
            public array $pages = [];

            public function __construct(private readonly \Sylius\Component\Core\Model\ProductInterface $product)
            {
            }

            public function search(\Sylius\Component\Core\Model\ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array
            {
                $this->pages[] = [$offset, $limit];

                return array_fill(0, $limit, $this->product);
            }

            public function findOne(\Sylius\Component\Core\Model\ChannelInterface $channel, string $localeCode, string $codeOrSlug): ?\Sylius\Component\Core\Model\ProductInterface
            {
                return null;
            }
        };
        $skill = new ProductSearchSkill($endless, $catalogue->presenter());

        $last = $skill->run(['query' => 'r', 'limit' => 25, 'offset' => 190], $catalogue->context(), 'de_DE');
        $before = $skill->run(['query' => 'r', 'limit' => 2, 'offset' => 197], $catalogue->context(), 'de_DE');

        // The limit shrinks to what is left of the window, plus the one probe row.
        self::assertSame([[190, 11], [197, 3]], $endless->pages);
        self::assertFalse($last->data['hasMore'] ?? null);
        self::assertTrue($before->data['hasMore'] ?? null);
        self::assertSame(199, $before->data['nextOffset'] ?? null);
    }

    public function testASentenceFallsBackToItsWordsLongestFirst(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductSearchSkill($catalogue, $catalogue->presenter());
        $result = $skill->run(['query' => 'Do you have a Corona or Robusto?', 'limit' => 10, 'offset' => 0], $catalogue->context(), 'de_DE');

        // At most four words, and the page is not full, so all four are tried.
        self::assertSame(['Do you have a Corona or Robusto?', 'Robusto', 'Corona', 'have', 'you'], $catalogue->searches);
        self::assertSame(['ROBUSTO-1', 'CORONA-2'], array_column($result->data['products'] ?? [], 'code'));
        self::assertSame(['Robusto', 'Corona'], $result->data['matchedTerms'] ?? null);
        self::assertSame('No product name contains "Do you have a Corona or Robusto?"; 2 products match "Robusto", "Corona".', $result->summary);
        self::assertFalse($result->data['hasMore'] ?? null);
    }

    public function testTheFallbackStopsOnceThePageIsFull(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductSearchSkill($catalogue, $catalogue->presenter());
        $result = $skill->run(['query' => 'Robusto or Corona', 'limit' => 1, 'offset' => 0], $catalogue->context(), 'de_DE');

        self::assertSame(['Robusto or Corona', 'Robusto'], $catalogue->searches);
        self::assertSame(['ROBUSTO-1'], array_column($result->data['products'] ?? [], 'code'));
    }

    public function testTheFallbackTriesAtMostFourWordsAndNoShortOnes(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductSearchSkill($catalogue, $catalogue->presenter());
        $skill->run(['query' => 'aaaaaa bbbbb cccc ddd ee f gggggggg aaaaaa', 'limit' => 10, 'offset' => 0], $catalogue->context(), 'de_DE');

        self::assertSame(['aaaaaa bbbbb cccc ddd ee f gggggggg aaaaaa', 'gggggggg', 'aaaaaa', 'bbbbb', 'cccc'], $catalogue->searches);
    }

    public function testASingleWordIsNotSearchedTwice(): void
    {
        $catalogue = Catalogue::shop();
        $result = (new ProductSearchSkill($catalogue, $catalogue->presenter()))->run(['query' => 'meerschaum', 'limit' => 10, 'offset' => 0], $catalogue->context(), 'de_DE');

        self::assertSame(['meerschaum'], $catalogue->searches);
        self::assertSame('No products found for "meerschaum".', $result->summary);
        self::assertSame([], $result->data['products'] ?? null);
        self::assertSame('', $result->text);
    }

    public function testASecondPageDoesNotFallBack(): void
    {
        $catalogue = Catalogue::shop();
        (new ProductSearchSkill($catalogue, $catalogue->presenter()))->run(['query' => 'big robusto', 'limit' => 10, 'offset' => 10], $catalogue->context(), 'de_DE');

        self::assertSame(['big robusto'], $catalogue->searches);
    }

    public function testFallbackHitsWithoutAPriceDoNotCount(): void
    {
        $catalogue = Catalogue::shop();
        $result = (new ProductSearchSkill($catalogue, $catalogue->presenter()))->run(['query' => 'unpriced things', 'limit' => 10, 'offset' => 0], $catalogue->context(), 'de_DE');

        self::assertSame([], $result->data['products'] ?? null);
        self::assertArrayNotHasKey('matchedTerms', $result->data ?? []);
    }

    public function testArgumentsAreNormalised(): void
    {
        $skill = $this->skill();

        self::assertSame(['query' => 'big robusto', 'limit' => 10, 'offset' => 0], $skill->arguments(new SkillInput("  big \n robusto ")));
        self::assertSame(['query' => 'corona', 'limit' => 25, 'offset' => 199], $skill->arguments(new SkillInput('ignored', ['query' => 'corona', 'limit' => 25, 'offset' => 199])));
        self::assertSame(['query' => 'x', 'limit' => 1, 'offset' => 0], $skill->arguments(new SkillInput('x', ['limit' => 1])));
        self::assertSame(str_repeat('a', 100), $skill->arguments(new SkillInput(str_repeat('a', 150)))['query']);
    }

    /** @return iterable<string, array{SkillInput, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'no term' => [new SkillInput(''), 'message.parts'];
        yield 'blank term' => [new SkillInput(" \t "), 'message.parts'];
        yield 'query not a string' => [new SkillInput('x', ['query' => ['a']]), 'message.parts.data.query'];
        yield 'limit zero' => [new SkillInput('x', ['limit' => 0]), 'message.parts.data.limit'];
        yield 'limit too high' => [new SkillInput('x', ['limit' => 26]), 'message.parts.data.limit'];
        yield 'limit a string' => [new SkillInput('x', ['limit' => '5']), 'message.parts.data.limit'];
        yield 'negative offset' => [new SkillInput('x', ['offset' => -1]), 'message.parts.data.offset'];
        yield 'offset past the window' => [new SkillInput('x', ['offset' => 200]), 'message.parts.data.offset'];
        yield 'offset a float' => [new SkillInput('x', ['offset' => 1.0]), 'message.parts.data.offset'];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsAreRejected(SkillInput $input, string $field): void
    {
        try {
            $this->skill()->arguments($input);
            self::fail('No error.');
        } catch (A2aError $error) {
            self::assertSame(A2aError::INVALID_PARAMS, $error->getCode());
            self::assertSame($field, $error->toArray()['data'][0]['fieldViolations'][0]['field'] ?? null);
        }
    }

    public function testArgumentsFromAReferenceAreClampedAgain(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductSearchSkill($catalogue, $catalogue->presenter());
        $skill->run(['query' => 'robusto', 'limit' => 1000, 'offset' => -5], $catalogue->context(), 'de_DE');
        $skill->run(['query' => 'robusto'], $catalogue->context(), 'de_DE');

        self::assertSame(['robusto', 'robusto'], $catalogue->searches);
    }

    public function testTheCardDescribesTheSkill(): void
    {
        $card = $this->skill()->card();

        self::assertSame('product-search', $card['id']);
        self::assertSame('product-search', $this->skill()->id());
        self::assertSame('Product search', $card['name']);
        self::assertContains('search', $card['tags']);
        self::assertSame(['text/plain', 'application/json'], $card['inputModes']);
        self::assertSame(['application/json', 'text/plain'], $card['outputModes']);
    }

    private function skill(): ProductSearchSkill
    {
        $catalogue = Catalogue::shop();

        return new ProductSearchSkill($catalogue, $catalogue->presenter());
    }

    private function search(string $query, int $limit = 10, int $offset = 0): SkillResult
    {
        $catalogue = Catalogue::shop();

        return (new ProductSearchSkill($catalogue, $catalogue->presenter()))->run(['query' => $query, 'limit' => $limit, 'offset' => $offset], $catalogue->context(), 'de_DE');
    }
}
