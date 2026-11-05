<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Catalog;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Inventory\Checker\AvailabilityCheckerInterface;

/**
 * What an agent gets to see of a product: what the product page shows an
 * anonymous visitor, no more.
 *
 * Prices are the channel prices the storefront renders — gross in a shop that
 * prices with tax included — in minor units plus the currency, and formatted
 * for the locale with the shop's own money formatter. Availability comes from
 * the inventory availability checker the storefront uses, so an untracked
 * variant counts as available just as it does in the shop.
 *
 * A variant without a price in the channel cannot be bought there and is left
 * out; a product left without any is left out as a whole (null).
 */
final class ProductPresenter
{
    private const SUMMARY_LENGTH = 300;
    private const DESCRIPTION_LENGTH = 2000;
    private const MAX_VARIANTS = 50;
    private const MAX_ATTRIBUTES = 30;

    public function __construct(
        private readonly AvailabilityCheckerInterface $availability,
        private readonly MoneyFormatterInterface $money,
        private readonly ?int $ageRestriction = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function summary(ProductInterface $product, AgentContext $context, string $localeCode): ?array
    {
        return $this->present($product, $context, $localeCode, false);
    }

    /** @return array<string, mixed>|null */
    public function detail(ProductInterface $product, AgentContext $context, string $localeCode): ?array
    {
        return $this->present($product, $context, $localeCode, true);
    }

    /**
     * One line per product for the text/plain rendering of a result.
     *
     * @param array<string, mixed> $product as summary() or detail() returned it
     */
    public function line(array $product): string
    {
        /** @var array{formatted: string} $price */
        $price = $product['price'];
        $line = (string) $product['name'].' — '.(true === $product['priceIsFrom'] ? 'from ' : '').$price['formatted']
            .' — '.(true === $product['available'] ? 'in stock' : 'out of stock');
        if (isset($product['url'])) {
            $line .= ' — '.(string) $product['url'];
        }

        return $line;
    }

    /** @return array<string, mixed>|null */
    private function present(ProductInterface $product, AgentContext $context, string $localeCode, bool $detail): ?array
    {
        $currency = $context->channel->getBaseCurrency()?->getCode();
        if (null === $currency || '' === $currency) {
            return null;
        }

        $translation = $product->getTranslation($localeCode);
        $code = (string) $product->getCode();
        $name = self::text($translation->getName()) ?? $code;

        $variants = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            $presented = $this->variant($variant, $context, $localeCode, $currency, $name);
            if (null !== $presented) {
                $variants[] = $presented;
            }
        }
        if ([] === $variants) {
            return null;
        }

        $amounts = array_map(static fn (array $variant): int => $variant['price']['amount'], $variants);
        $lowest = min($amounts);
        $available = [] !== array_filter($variants, static fn (array $variant): bool => $variant['available']);

        $presented = [
            'code' => $code,
            'name' => $name,
            'price' => $this->price($lowest, $currency, $localeCode),
            'priceIsFrom' => $lowest !== max($amounts),
            'available' => $available,
            'availability' => $available ? 'in_stock' : 'out_of_stock',
        ];

        $slug = self::text($translation->getSlug());
        if (null !== $slug) {
            $presented['url'] = $context->url($localeCode.'/products/'.rawurlencode($slug));
        }

        $description = $detail
            ? self::plain($translation->getDescription(), self::DESCRIPTION_LENGTH) ?? self::plain($translation->getShortDescription(), self::DESCRIPTION_LENGTH)
            : self::plain($translation->getShortDescription(), self::SUMMARY_LENGTH) ?? self::plain($translation->getDescription(), self::SUMMARY_LENGTH);
        if (null !== $description) {
            $presented['description'] = $description;
        }

        if (null !== $this->ageRestriction) {
            // Carried on every product, so an agent cannot mistake a restricted
            // product for ordinary stock (the same field the ACP feed uses).
            $presented['ageRestriction'] = $this->ageRestriction;
        }

        if ($detail) {
            $attributes = $this->attributes($product, $context, $localeCode);
            if ([] !== $attributes) {
                $presented['attributes'] = $attributes;
            }
        }

        $presented['variants'] = \array_slice($variants, 0, self::MAX_VARIANTS);

        return $presented;
    }

    /** @return array{code: string, name: string, price: array{amount: int, currency: string, formatted: string}, originalPrice?: array{amount: int, currency: string, formatted: string}, available: bool}|null */
    private function variant(ProductVariantInterface $variant, AgentContext $context, string $localeCode, string $currency, string $productName): ?array
    {
        $pricing = $variant->getChannelPricingForChannel($context->channel);
        $amount = $pricing?->getPrice();
        if (null === $pricing || null === $amount || $amount < 0) {
            return null;
        }

        $presented = [
            'code' => (string) $variant->getCode(),
            'name' => self::text($variant->getTranslation($localeCode)->getName()) ?? $productName,
            'price' => $this->price($amount, $currency, $localeCode),
        ];

        // The struck-through price the storefront shows next to a discount.
        $original = $pricing->getOriginalPrice();
        if (null !== $original && $original > $amount) {
            $presented['originalPrice'] = $this->price($original, $currency, $localeCode);
        }

        $presented['available'] = $this->availability->isStockAvailable($variant);

        return $presented;
    }

    /** @return array{amount: int, currency: string, formatted: string} */
    private function price(int $amount, string $currency, string $localeCode): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'formatted' => $this->money->format($amount, $currency, $localeCode),
        ];
    }

    /** @return list<array{name: string, value: string}> */
    private function attributes(ProductInterface $product, AgentContext $context, string $localeCode): array
    {
        $fallback = (string) $context->channel->getDefaultLocale()?->getCode();
        $attributes = [];
        foreach ($product->getAttributesByLocale($localeCode, '' === $fallback ? $localeCode : $fallback) as $attribute) {
            $value = $attribute->getValue();
            $name = self::text($attribute->getName());
            if (null === $name || !(\is_string($value) || \is_int($value) || \is_float($value))) {
                continue;
            }
            $value = self::plain((string) $value, self::SUMMARY_LENGTH);
            if (null !== $value) {
                $attributes[] = ['name' => $name, 'value' => $value];
            }
            if (\count($attributes) >= self::MAX_ATTRIBUTES) {
                break;
            }
        }

        return $attributes;
    }

    private static function text(?string $text): ?string
    {
        $text = trim((string) $text);

        return '' === $text ? null : $text;
    }

    private static function plain(?string $text, int $length): ?string
    {
        // Stripped first and checked after: `<p> </p>` is not empty on the way
        // in and is on the way out.
        // Entities are decoded as well: a description from the rich-text editor
        // says `&amp;`, and an agent is not a browser.
        $text = html_entity_decode(strip_tags((string) $text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = self::text((string) preg_replace('/\s+/u', ' ', $text));
        if (null === $text) {
            return null;
        }

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
