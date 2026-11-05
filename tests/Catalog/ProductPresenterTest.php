<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Catalog;

use Koh\SyliusA2a\Catalog\ProductPresenter;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Product\Model\ProductAttribute;
use Sylius\Component\Product\Model\ProductAttributeValue;

#[CoversClass(ProductPresenter::class)]
final class ProductPresenterTest extends TestCase
{
    public function testASummaryCarriesPriceAvailabilityAndTheProductPage(): void
    {
        $catalogue = Catalogue::shop();
        $summary = $catalogue->presenter()->summary($catalogue->products[0], $catalogue->context(), 'de_DE');

        self::assertSame([
            'code' => 'ROBUSTO-1',
            'name' => 'Robusto Especial',
            'price' => ['amount' => 1250, 'currency' => 'EUR', 'formatted' => "12,50\u{a0}€"],
            'priceIsFrom' => true,
            'available' => true,
            'availability' => 'in_stock',
            'url' => 'https://shop.example.com/de_DE/products/robusto-especial',
            'description' => 'A medium Robusto & more.',
            'variants' => [
                [
                    'code' => 'ROBUSTO-1-SINGLE',
                    'name' => 'Robusto Especial',
                    'price' => ['amount' => 1250, 'currency' => 'EUR', 'formatted' => "12,50\u{a0}€"],
                    'available' => true,
                ],
                [
                    'code' => 'ROBUSTO-1-BOX',
                    'name' => 'Robusto Especial',
                    'price' => ['amount' => 24250, 'currency' => 'EUR', 'formatted' => "242,50\u{a0}€"],
                    'originalPrice' => ['amount' => 25000, 'currency' => 'EUR', 'formatted' => "250,00\u{a0}€"],
                    'available' => true,
                ],
            ],
        ], $summary);
    }

    public function testPricesAreFormattedForTheRequestedLocale(): void
    {
        $catalogue = Catalogue::shop();
        $summary = $catalogue->presenter()->summary($catalogue->products[0], $catalogue->context(), 'en_GB');

        self::assertSame('€12.50', $summary['price']['formatted'] ?? null);
        self::assertSame('Robusto Special', $summary['name'] ?? null);
        self::assertSame('https://shop.example.com/en_GB/products/robusto-special', $summary['url'] ?? null);
    }

    public function testAvailabilityFollowsTheInventoryRulesOfTheStorefront(): void
    {
        $catalogue = Catalogue::shop();
        $presenter = $catalogue->presenter();

        // Tracked and none on hand: out of stock.
        $corona = $presenter->summary($catalogue->products[1], $catalogue->context(), 'de_DE');
        self::assertFalse($corona['available'] ?? null);
        self::assertSame('out_of_stock', $corona['availability'] ?? null);
        self::assertFalse($corona['priceIsFrom'] ?? null);

        // Not tracked: available, whatever the count says.
        $cutter = $presenter->summary($catalogue->products[2], $catalogue->context(), 'de_DE');
        self::assertTrue($cutter['available'] ?? null);
        self::assertSame('in_stock', $cutter['availability'] ?? null);
    }

    public function testOneAvailableVariantMakesTheProductAvailable(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [
            ['code' => 'A', 'price' => 100, 'onHand' => 0],
            ['code' => 'B', 'price' => 100, 'onHand' => 1],
        ]);

        self::assertTrue($catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE')['available'] ?? null);
    }

    public function testOnlyEnabledPricedVariantsAreShown(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [
            ['code' => 'OFF', 'price' => 100, 'enabled' => false],
            ['code' => 'FREE', 'price' => 0],
            ['code' => 'NEGATIVE', 'price' => -1],
            ['code' => 'NONE', 'price' => null],
        ]);

        $summary = $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE');

        self::assertSame(['FREE'], array_column($summary['variants'] ?? [], 'code'));
        self::assertSame(0, $summary['price']['amount'] ?? null);
    }

    public function testAProductWithoutAPricedVariantIsNotShown(): void
    {
        $catalogue = Catalogue::shop();

        self::assertNull($catalogue->presenter()->summary($catalogue->products[5], $catalogue->context(), 'de_DE'));
    }

    public function testAChannelWithoutCurrencyShowsNothing(): void
    {
        $catalogue = Catalogue::shop();
        $channel = new Channel();
        $channel->setCode('WEB');

        self::assertNull($catalogue->presenter()->summary($catalogue->products[0], $catalogue->context(channel: $channel), 'de_DE'));

        $channel->setBaseCurrency(new Currency());
        self::assertNull($catalogue->presenter()->summary($catalogue->products[0], $catalogue->context(channel: $channel), 'de_DE'));
    }

    public function testAnOriginalPriceIsOnlyShownWhenItIsHigher(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [
            ['code' => 'SAME', 'price' => 100, 'original' => 100],
            ['code' => 'LOWER', 'price' => 100, 'original' => 90],
            ['code' => 'HIGHER', 'price' => 100, 'original' => 101],
        ]);

        $variants = $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE')['variants'] ?? [];

        self::assertArrayNotHasKey('originalPrice', $variants[0]);
        self::assertArrayNotHasKey('originalPrice', $variants[1]);
        self::assertSame(101, $variants[2]['originalPrice']['amount']);
    }

    public function testTheSummaryPrefersTheShortDescriptionAndTheDetailTheLongOne(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]], shortDescription: 'Short.', description: 'Long.');
        $presenter = $catalogue->presenter();

        self::assertSame('Short.', $presenter->summary($product, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertSame('Long.', $presenter->detail($product, $catalogue->context(), 'de_DE')['description'] ?? null);
    }

    public function testEachFallsBackToTheOtherDescription(): void
    {
        $catalogue = new Catalogue();
        $onlyLong = $catalogue->add('L', ['de_DE' => 'Long'], [['code' => 'L', 'price' => 100]], shortDescription: '<p> </p>', description: 'Long.');
        $onlyShort = $catalogue->add('S', ['de_DE' => 'Short'], [['code' => 'S', 'price' => 100]], shortDescription: 'Short.');
        $none = $catalogue->add('N', ['de_DE' => 'None'], [['code' => 'N', 'price' => 100]]);
        $presenter = $catalogue->presenter();

        self::assertSame('Long.', $presenter->summary($onlyLong, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertSame('Short.', $presenter->detail($onlyShort, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertArrayNotHasKey('description', $presenter->summary($none, $catalogue->context(), 'de_DE') ?? []);
        self::assertArrayNotHasKey('description', $presenter->detail($none, $catalogue->context(), 'de_DE') ?? []);
    }

    public function testDescriptionsAreCutForASummaryAndForADetail(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]], shortDescription: str_repeat('s', 301), description: str_repeat('l', 2001));
        $exact = $catalogue->add('E', ['de_DE' => 'Exact'], [['code' => 'E', 'price' => 100]], shortDescription: str_repeat('s', 300), description: str_repeat('l', 2000));
        $presenter = $catalogue->presenter();

        self::assertSame(str_repeat('s', 299).'…', $presenter->summary($product, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertSame(str_repeat('l', 1999).'…', $presenter->detail($product, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertSame(str_repeat('s', 300), $presenter->summary($exact, $catalogue->context(), 'de_DE')['description'] ?? null);
        self::assertSame(str_repeat('l', 2000), $presenter->detail($exact, $catalogue->context(), 'de_DE')['description'] ?? null);
    }

    public function testAProductWithoutNameOrSlugIsShownByCodeWithoutAUrl(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P-1', ['de_DE' => ' '], [['code' => 'P-1', 'price' => 100]]);
        $product->getTranslation('de_DE')->setSlug(null);

        $summary = $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE');

        self::assertSame('P-1', $summary['name'] ?? null);
        self::assertArrayNotHasKey('url', $summary ?? []);
    }

    public function testASlugIsEncodedIntoTheUrl(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]]);
        $product->getTranslation('de_DE')->setSlug('pfeife ä');

        self::assertSame('https://shop.example.com/de_DE/products/pfeife%20%C3%A4', $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE')['url'] ?? null);
    }

    public function testAVariantNameWinsOverTheProductName(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]]);
        $product->getVariants()->first()->setName('Pipe, briar');

        self::assertSame('Pipe, briar', $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE')['variants'][0]['name'] ?? null);
    }

    public function testTheAgeRestrictionIsCarriedWhenConfigured(): void
    {
        $catalogue = Catalogue::shop();

        self::assertSame(18, $catalogue->presenter(18)->summary($catalogue->products[0], $catalogue->context(), 'de_DE')['ageRestriction'] ?? null);
        self::assertSame(0, $catalogue->presenter(0)->summary($catalogue->products[0], $catalogue->context(), 'de_DE')['ageRestriction'] ?? null);
        self::assertArrayNotHasKey('ageRestriction', $catalogue->presenter()->summary($catalogue->products[0], $catalogue->context(), 'de_DE') ?? []);
    }

    public function testVariantsAreCappedAtFifty(): void
    {
        $catalogue = new Catalogue();
        $variants = [];
        for ($i = 0; $i < 51; ++$i) {
            $variants[] = ['code' => 'V'.$i, 'price' => 100 + $i];
        }
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], $variants);

        $summary = $catalogue->presenter()->summary($product, $catalogue->context(), 'de_DE');

        self::assertCount(50, $summary['variants'] ?? []);
        // The price range still covers every variant.
        self::assertTrue($summary['priceIsFrom'] ?? null);
    }

    public function testTheDetailCarriesScalarAttributesAndASummaryDoesNot(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]]);
        $this->attribute($product, 'strength', 'Strength', 'medium');
        $this->attribute($product, 'length', 'Length', 124);
        $this->attribute($product, 'ring', 'Ring', 1.5);
        $this->attribute($product, 'origin', 'Origin', '<b>Cuba</b>');
        $this->attribute($product, 'flags', 'Flags', ['a', 'b']);
        $this->attribute($product, 'blank', 'Blank', ' ');
        $this->attribute($product, 'nameless', ' ', 'x');
        $presenter = $catalogue->presenter();

        self::assertSame([
            ['name' => 'Strength', 'value' => 'medium'],
            ['name' => 'Length', 'value' => '124'],
            ['name' => 'Ring', 'value' => '1.5'],
            ['name' => 'Origin', 'value' => 'Cuba'],
        ], $presenter->detail($product, $catalogue->context(), 'de_DE')['attributes'] ?? null);
        self::assertArrayNotHasKey('attributes', $presenter->summary($product, $catalogue->context(), 'de_DE') ?? []);
    }

    public function testAttributesAreCappedAndAbsentWhenThereAreNone(): void
    {
        $catalogue = new Catalogue();
        $product = $catalogue->add('P', ['de_DE' => 'Pipe'], [['code' => 'P', 'price' => 100]]);
        $bare = $catalogue->add('B', ['de_DE' => 'Bare'], [['code' => 'B', 'price' => 100]]);
        for ($i = 0; $i < 31; ++$i) {
            $this->attribute($product, 'a'.$i, 'A'.$i, 'v');
        }

        self::assertCount(30, $catalogue->presenter()->detail($product, $catalogue->context(), 'de_DE')['attributes'] ?? []);
        self::assertArrayNotHasKey('attributes', $catalogue->presenter()->detail($bare, $catalogue->context(), 'de_DE') ?? []);
    }

    public function testTheTextLineNamesPriceAvailabilityAndPage(): void
    {
        $presenter = Catalogue::shop()->presenter();

        self::assertSame('Pipe — from 1,00 € — in stock — https://x/p', $presenter->line([
            'name' => 'Pipe', 'price' => ['formatted' => '1,00 €'], 'priceIsFrom' => true, 'available' => true, 'url' => 'https://x/p',
        ]));
        self::assertSame('Pipe — 1,00 € — out of stock', $presenter->line([
            'name' => 'Pipe', 'price' => ['formatted' => '1,00 €'], 'priceIsFrom' => false, 'available' => false,
        ]));
    }

    private function attribute(\Sylius\Component\Core\Model\ProductInterface $product, string $code, string $name, mixed $value): void
    {
        $attribute = new ProductAttribute();
        $attribute->setCurrentLocale('de_DE');
        $attribute->setFallbackLocale('de_DE');
        $attribute->setCode($code);
        $attribute->setName($name);
        $attribute->setType(\is_array($value) ? 'select' : 'text');
        $attribute->setStorageType(\is_array($value) ? 'json' : (\is_int($value) ? 'integer' : (\is_float($value) ? 'float' : 'text')));

        $attributeValue = new ProductAttributeValue();
        $attributeValue->setAttribute($attribute);
        $attributeValue->setLocaleCode('de_DE');
        $attributeValue->setValue($value);
        $product->addAttribute($attributeValue);
    }
}
