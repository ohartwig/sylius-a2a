<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Catalog;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * The storefront's product search, over Sylius's product repository.
 *
 * The same conditions the shop's own search grid and the UCP and ACP catalogue
 * paths apply: assigned to the channel, enabled, a translation in the locale,
 * the name containing the term. All of them sit in SQL, so a page is cut by the
 * database and a disabled product or one from another channel never reaches an
 * agent. The lookup by code or slug keeps the same conditions — the product
 * page of a disabled product answers 404 in the shop, and so does it here.
 */
final class DoctrineProductSource implements ProductSource
{
    /** @param EntityRepository<ProductInterface> $products */
    public function __construct(private readonly EntityRepository $products)
    {
    }

    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array
    {
        /** @var list<ProductInterface> $found */
        $found = $this->searchQuery($channel, $localeCode, $term, $offset, $limit)->getQuery()->getResult();

        return $found;
    }

    public function findOne(ChannelInterface $channel, string $localeCode, string $codeOrSlug): ?ProductInterface
    {
        foreach (['o.code', 'translation.slug'] as $field) {
            $product = $this->lookupQuery($channel, $localeCode, $field, $codeOrSlug)->getQuery()->getOneOrNullResult();
            if ($product instanceof ProductInterface) {
                return $product;
            }
        }

        return null;
    }

    public function searchQuery(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): QueryBuilder
    {
        // The translation join is filtered to one locale, so there is at most
        // one translation row per product and the row limit is a product limit.
        return $this->visible($channel, $localeCode)
            ->andWhere('translation.name LIKE :name')
            ->setParameter('name', '%'.addcslashes($term, '%_\\').'%')
            ->addOrderBy('translation.name', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));
    }

    public function lookupQuery(ChannelInterface $channel, string $localeCode, string $field, string $value): QueryBuilder
    {
        return $this->visible($channel, $localeCode)
            ->andWhere($field.' = :value')
            ->setParameter('value', $value)
            ->setMaxResults(1);
    }

    private function visible(ChannelInterface $channel, string $localeCode): QueryBuilder
    {
        return $this->products->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->setParameter('locale', $localeCode)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true);
    }
}
