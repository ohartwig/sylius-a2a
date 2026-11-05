<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Catalog;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Where the skills read products from: only what an anonymous visitor of the
 * channel's storefront can reach — enabled products assigned to the channel,
 * with a translation in the requested locale.
 */
interface ProductSource
{
    /**
     * Products whose name in the locale contains the term, at most `$limit`
     * of them starting at `$offset`, ordered by name.
     *
     * @return list<ProductInterface>
     */
    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array;

    /** The product with this code, or else this slug in the locale. */
    public function findOne(ChannelInterface $channel, string $localeCode, string $codeOrSlug): ?ProductInterface;
}
