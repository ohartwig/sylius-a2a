<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Skill;

use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusA2a\Skill\ProductDetailSkill;
use Koh\SyliusA2a\Skill\SkillInput;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductDetailSkill::class)]
final class ProductDetailSkillTest extends TestCase
{
    public function testAProductIsFoundByCodeOrSlug(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductDetailSkill($catalogue, $catalogue->presenter());

        $byCode = $skill->run(['code' => 'ROBUSTO-1'], $catalogue->context(), 'de_DE');
        $bySlug = $skill->run(['code' => 'robusto-especial'], $catalogue->context(), 'de_DE');

        self::assertSame('Found "Robusto Especial".', $byCode->summary);
        self::assertSame('product', $byCode->artifactName);
        self::assertSame('de_DE', $byCode->data['locale'] ?? null);
        self::assertSame('ROBUSTO-1', $byCode->data['product']['code'] ?? null);
        self::assertSame($byCode->data, $bySlug->data);
        self::assertSame(
            "Robusto Especial — from 12,50\u{a0}€ — in stock — https://shop.example.com/de_DE/products/robusto-especial\n"
            ."- Robusto Especial — 12,50\u{a0}€ — in stock\n"
            ."- Robusto Especial — 242,50\u{a0}€ — in stock\n"
            ."\nA medium Robusto & more.",
            $byCode->text,
        );
    }

    public function testATextWithoutDescriptionEndsWithTheVariants(): void
    {
        $catalogue = Catalogue::shop();
        $result = (new ProductDetailSkill($catalogue, $catalogue->presenter()))->run(['code' => 'CUTTER-3'], $catalogue->context(), 'de_DE');

        self::assertSame("Cigar Cutter — 19,99\u{a0}€ — in stock — https://shop.example.com/de_DE/products/cigar-cutter\n- Cigar Cutter — 19,99\u{a0}€ — in stock", $result->text);
    }

    public function testWhatTheStorefrontDoesNotShowIsNotFound(): void
    {
        $catalogue = Catalogue::shop();
        $skill = new ProductDetailSkill($catalogue, $catalogue->presenter());

        foreach (['HIDDEN-4', 'OTHER-5', 'UNPRICED-6', 'NOPE'] as $code) {
            $result = $skill->run(['code' => $code], $catalogue->context(), 'de_DE');
            self::assertNull($result->data, $code);
            self::assertSame(\sprintf('This shop has no product with the code or slug "%s".', $code), $result->summary);
        }
        self::assertNull($skill->run([], $catalogue->context(), 'de_DE')->data);
    }

    public function testArguments(): void
    {
        $skill = new ProductDetailSkill(Catalogue::shop(), Catalogue::shop()->presenter());

        self::assertSame(['code' => 'A'], $skill->arguments(new SkillInput(' A ')));
        self::assertSame(['code' => 'B'], $skill->arguments(new SkillInput('A', ['code' => 'B', 'slug' => 'c'])));
        self::assertSame(['code' => 'c'], $skill->arguments(new SkillInput('A', ['slug' => 'c'])));
        self::assertSame(['code' => str_repeat('x', 255)], $skill->arguments(new SkillInput(str_repeat('x', 255))));
    }

    public function testInvalidArgumentsAreRejected(): void
    {
        $skill = new ProductDetailSkill(Catalogue::shop(), Catalogue::shop()->presenter());

        foreach ([new SkillInput(''), new SkillInput(str_repeat('x', 256)), new SkillInput('x', ['code' => 5])] as $input) {
            try {
                $skill->arguments($input);
                self::fail('No error.');
            } catch (A2aError $error) {
                self::assertSame(A2aError::INVALID_PARAMS, $error->getCode());
                self::assertSame('message.parts.data.code', $error->toArray()['data'][0]['fieldViolations'][0]['field'] ?? null);
            }
        }
    }

    public function testTheCardDescribesTheSkill(): void
    {
        $skill = new ProductDetailSkill(Catalogue::shop(), Catalogue::shop()->presenter());

        self::assertSame('product-detail', $skill->id());
        self::assertSame('product-detail', $skill->card()['id']);
        self::assertSame('Product details', $skill->card()['name']);
        self::assertSame(['commerce', 'catalogue', 'products'], $skill->card()['tags']);
    }
}
