<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Fixture;

use Koh\SyliusA2a\Catalog\ProductPresenter;
use Koh\SyliusA2a\Catalog\ProductSource;
use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Koh\SyliusA2a\Skill\ProductDetailSkill;
use Koh\SyliusA2a\Skill\ProductSearchSkill;
use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatter;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslation;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Inventory\Checker\AvailabilityChecker;
use Sylius\Component\Locale\Model\Locale;

/**
 * A small shop built from the real Sylius models, and an in-memory product
 * source that applies the same rules as the Doctrine one: enabled, in the
 * channel, translated into the locale, name contains the term.
 */
final class Catalogue implements ProductSource
{
    public const SECRET = 'test-secret';
    public const NOW = 1_800_000_000;

    public ChannelInterface $channel;

    /** @var list<ProductInterface> */
    public array $products = [];

    /** @var list<string> search terms in the order they were asked for */
    public array $searches = [];

    public function __construct(string $channelCode = 'WEB')
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $german = new Locale();
        $german->setCode('de_DE');
        $english = new Locale();
        $english->setCode('en_GB');

        $this->channel = new Channel();
        $this->channel->setCode($channelCode);
        $this->channel->setName('Example Shop');
        $this->channel->setBaseCurrency($currency);
        $this->channel->setDefaultLocale($german);
        $this->channel->addLocale($german);
        $this->channel->addLocale($english);
    }

    /**
     * @param array<string, string>                                                   $names    by locale
     * @param list<array{code: string, price: int|null, onHand?: int, tracked?: bool, original?: int, enabled?: bool}> $variants
     */
    public function add(string $code, array $names, array $variants, bool $enabled = true, ?string $shortDescription = null, ?string $description = null, bool $inChannel = true): ProductInterface
    {
        $product = new Product();
        $product->setCode($code);
        $product->setEnabled($enabled);
        $product->setCurrentLocale(array_key_first($names));
        $product->setFallbackLocale(array_key_first($names));
        foreach ($names as $locale => $name) {
            $translation = new ProductTranslation();
            $translation->setLocale($locale);
            $translation->setName($name);
            $translation->setSlug(strtolower(str_replace(' ', '-', $name)));
            $translation->setShortDescription($shortDescription);
            $translation->setDescription($description);
            $product->addTranslation($translation);
        }
        if ($inChannel) {
            $product->addChannel($this->channel);
        }

        foreach ($variants as $spec) {
            $variant = new ProductVariant();
            $variant->setCode($spec['code']);
            $variant->setCurrentLocale(array_key_first($names));
            $variant->setFallbackLocale(array_key_first($names));
            $variant->setEnabled($spec['enabled'] ?? true);
            $variant->setOnHand($spec['onHand'] ?? 5);
            $variant->setTracked($spec['tracked'] ?? true);
            if (null !== $spec['price']) {
                $pricing = new ChannelPricing();
                $pricing->setChannelCode((string) $this->channel->getCode());
                $pricing->setPrice($spec['price']);
                $pricing->setOriginalPrice($spec['original'] ?? null);
                $variant->addChannelPricing($pricing);
            }
            $product->addVariant($variant);
        }

        $this->products[] = $product;

        return $product;
    }

    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array
    {
        $this->searches[] = $term;
        $found = array_values(array_filter(
            $this->visible($channel, $localeCode),
            static fn (ProductInterface $product): bool => str_contains(
                mb_strtolower((string) $product->getTranslation($localeCode)->getName()),
                mb_strtolower($term),
            ),
        ));
        usort($found, static fn (ProductInterface $a, ProductInterface $b): int => strcmp(
            (string) $a->getTranslation($localeCode)->getName(),
            (string) $b->getTranslation($localeCode)->getName(),
        ));

        return \array_slice($found, $offset, $limit);
    }

    public function findOne(ChannelInterface $channel, string $localeCode, string $codeOrSlug): ?ProductInterface
    {
        foreach ($this->visible($channel, $localeCode) as $product) {
            if ($product->getCode() === $codeOrSlug || $product->getTranslation($localeCode)->getSlug() === $codeOrSlug) {
                return $product;
            }
        }

        return null;
    }

    public function presenter(?int $ageRestriction = null): ProductPresenter
    {
        return new ProductPresenter(new AvailabilityChecker(), new MoneyFormatter(), $ageRestriction);
    }

    public function context(string $baseUrl = 'https://shop.example.com', ?ChannelInterface $channel = null): AgentContext
    {
        return new AgentContext(
            channel: $channel ?? $this->channel,
            baseUrl: $baseUrl,
            localeCode: 'de_DE',
            localeCodes: ['de_DE', 'en_GB'],
        );
    }

    public function contextFactory(?ChannelInterface $channel = null, string $baseUrl = 'https://shop.example.com'): AgentContextFactoryInterface
    {
        $context = $this->context($baseUrl, $channel);

        return new class($context) implements AgentContextFactoryInterface {
            public function __construct(private readonly AgentContext $context)
            {
            }

            public function create(?ChannelInterface $channel = null): AgentContext
            {
                return $this->context;
            }
        };
    }

    public function methods(?ChannelInterface $channel = null, int $now = self::NOW, int $ttl = 86400, ?int $ageRestriction = null): A2aMethods
    {
        $presenter = $this->presenter($ageRestriction);

        return new A2aMethods(
            [new ProductSearchSkill($this, $presenter), new ProductDetailSkill($this, $presenter)],
            $this->contextFactory($channel),
            new TaskIdCodec(self::SECRET, $ttl),
            static fn (): int => $now,
        );
    }

    /** A shop with a few cigars and an accessory. */
    public static function shop(): self
    {
        $catalogue = new self();
        $catalogue->add('ROBUSTO-1', ['de_DE' => 'Robusto Especial', 'en_GB' => 'Robusto Special'], [
            ['code' => 'ROBUSTO-1-SINGLE', 'price' => 1250],
            ['code' => 'ROBUSTO-1-BOX', 'price' => 24250, 'original' => 25000],
        ], shortDescription: '<p>A medium <strong>Robusto</strong> &amp; more.</p>');
        $catalogue->add('CORONA-2', ['de_DE' => 'Corona Clasica'], [
            ['code' => 'CORONA-2-SINGLE', 'price' => 900, 'onHand' => 0],
        ], description: 'Mild.');
        $catalogue->add('CUTTER-3', ['de_DE' => 'Cigar Cutter'], [
            ['code' => 'CUTTER-3', 'price' => 1999, 'onHand' => 0, 'tracked' => false],
        ]);
        $catalogue->add('HIDDEN-4', ['de_DE' => 'Robusto Hidden'], [['code' => 'HIDDEN-4', 'price' => 100]], enabled: false);
        $catalogue->add('OTHER-5', ['de_DE' => 'Robusto Elsewhere'], [['code' => 'OTHER-5', 'price' => 100]], inChannel: false);
        $catalogue->add('UNPRICED-6', ['de_DE' => 'Robusto Unpriced'], [['code' => 'UNPRICED-6', 'price' => null]]);

        return $catalogue;
    }

    /** @return list<ProductInterface> */
    private function visible(ChannelInterface $channel, string $localeCode): array
    {
        return array_values(array_filter(
            $this->products,
            static fn (ProductInterface $product): bool => $product->isEnabled()
                && $product->hasChannel($channel)
                && $product->getTranslations()->containsKey($localeCode),
        ));
    }
}
