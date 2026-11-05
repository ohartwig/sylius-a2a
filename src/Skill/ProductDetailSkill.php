<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Skill;

use Koh\SyliusA2a\Catalog\ProductPresenter;
use Koh\SyliusA2a\Catalog\ProductSource;
use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusAgentContracts\Context\AgentContext;

/**
 * One product by its code or URL slug, with every variant, its description and
 * its attributes — what the product page shows.
 */
final class ProductDetailSkill implements A2aSkillInterface
{
    public const ID = 'product-detail';
    private const MAX_CODE_LENGTH = 255;

    public function __construct(
        private readonly ProductSource $products,
        private readonly ProductPresenter $presenter,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function card(): array
    {
        return [
            'id' => self::ID,
            'name' => 'Product details',
            'description' => 'Returns one product of this shop by its code or URL slug, as product search returns them: every variant with its price (gross, in the shop currency) and availability, the description, the attributes and the product page URL. Send a data part {"skill": "product-detail", "code": "..."} (or "slug").',
            'tags' => ['commerce', 'catalogue', 'products'],
            'examples' => ['{"skill": "product-detail", "code": "EXAMPLE-CODE"}'],
            'inputModes' => ['application/json', 'text/plain'],
            'outputModes' => ['application/json', 'text/plain'],
        ];
    }

    public function arguments(SkillInput $input): array
    {
        $code = $input->data['code'] ?? $input->data['slug'] ?? $input->text;
        if (!\is_string($code)) {
            throw A2aError::invalidParams('message.parts.data.code', 'code must be a string');
        }
        $code = trim($code);
        if ('' === $code || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw A2aError::invalidParams('message.parts.data.code', \sprintf('a product code or slug of 1 to %d characters is required', self::MAX_CODE_LENGTH));
        }

        return ['code' => $code];
    }

    public function run(array $arguments, AgentContext $context, string $localeCode): SkillResult
    {
        $code = (string) ($arguments['code'] ?? '');
        $product = $this->products->findOne($context->channel, $localeCode, $code);
        $presented = null === $product ? null : $this->presenter->detail($product, $context, $localeCode);

        if (null === $presented) {
            return new SkillResult(
                summary: \sprintf('This shop has no product with the code or slug "%s".', $code),
                artifactName: 'product',
            );
        }

        $data = ['locale' => $localeCode, 'product' => $presented];
        $text = $this->presenter->line($presented);
        foreach ($presented['variants'] as $variant) {
            /** @var array{name: string, price: array{formatted: string}, available: bool} $variant */
            $text .= "\n- ".$variant['name'].' — '.$variant['price']['formatted'].' — '.($variant['available'] ? 'in stock' : 'out of stock');
        }
        if (isset($presented['description'])) {
            $text .= "\n\n".(string) $presented['description'];
        }

        return new SkillResult(
            summary: \sprintf('Found "%s".', (string) $presented['name']),
            artifactName: 'product',
            data: $data,
            text: $text,
        );
    }
}
