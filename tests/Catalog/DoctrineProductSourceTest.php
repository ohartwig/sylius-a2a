<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Koh\SyliusA2a\Catalog\DoctrineProductSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;

/**
 * Channel, enabled flag and locale are filtered in SQL — the conditions of the
 * storefront search — and a search page is cut by the database.
 */
#[CoversClass(DoctrineProductSource::class)]
final class DoctrineProductSourceTest extends TestCase
{
    public function testTheSearchCarriesOffsetAndLimit(): void
    {
        $queryBuilder = $this->source()->searchQuery($this->createStub(ChannelInterface::class), 'de_DE', 'robusto', 40, 11);

        self::assertSame(40, $queryBuilder->getFirstResult());
        self::assertSame(11, $queryBuilder->getMaxResults());
    }

    public function testOffsetAndLimitAreClampedToSaneValues(): void
    {
        $queryBuilder = $this->source()->searchQuery($this->createStub(ChannelInterface::class), 'de_DE', 'robusto', -5, 0);

        self::assertSame(0, $queryBuilder->getFirstResult());
        self::assertSame(1, $queryBuilder->getMaxResults());
    }

    public function testOnlyEnabledProductsOfTheChannelInTheLocaleMatchByName(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->source()->searchQuery($channel, 'de_DE', 'robusto', 0, 10);
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString('INNER JOIN o.translations translation WITH translation.locale = :locale', $dql);
        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('translation.name LIKE :name', $dql);
        self::assertStringContainsString('ORDER BY translation.name ASC, o.id ASC', $dql);
        self::assertSame($channel, $queryBuilder->getParameter('channel')?->getValue());
        self::assertTrue($queryBuilder->getParameter('enabled')?->getValue());
        self::assertSame('de_DE', $queryBuilder->getParameter('locale')?->getValue());
        self::assertSame('%robusto%', $queryBuilder->getParameter('name')?->getValue());
    }

    public function testWildcardsInTheTermAreText(): void
    {
        $queryBuilder = $this->source()->searchQuery($this->createStub(ChannelInterface::class), 'de_DE', '50%_off\\', 0, 10);

        self::assertSame('%50\%\_off\\\\%', $queryBuilder->getParameter('name')?->getValue());
    }

    public function testTheLookupKeepsTheVisibilityConditions(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->source()->lookupQuery($channel, 'en_GB', 'translation.slug', 'robusto-especial');
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('translation.locale = :locale', $dql);
        self::assertStringContainsString('translation.slug = :value', $dql);
        self::assertSame('robusto-especial', $queryBuilder->getParameter('value')?->getValue());
        self::assertSame('en_GB', $queryBuilder->getParameter('locale')?->getValue());
        self::assertSame(1, $queryBuilder->getMaxResults());
    }

    private function source(): DoctrineProductSource
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(
            static fn (string $alias): QueryBuilder => (new QueryBuilder($em))->select($alias)->from(Product::class, $alias),
        );

        return new DoctrineProductSource($repository);
    }
}
