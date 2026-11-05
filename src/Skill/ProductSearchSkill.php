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
 * Searches the channel's catalogue by product name, as the storefront's search
 * box does.
 *
 * Without a language model there is no understanding a sentence, so a message
 * is taken as the search term. When the whole term matches no product name —
 * an agent asking "do you have Robusto cigars?" — the words of the term are
 * tried one by one, longest first, and the result says which of them matched.
 *
 * The endpoint is public, so every bound is set here: a term is required and
 * cut at MAX_QUERY_LENGTH, a page holds at most MAX_LIMIT products, and paging
 * cannot reach past MAX_RESULTS — a broad term pages through a window, not the
 * whole catalogue.
 */
final class ProductSearchSkill implements A2aSkillInterface
{
    public const ID = 'product-search';
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 25;
    public const MAX_RESULTS = 200;
    public const MAX_QUERY_LENGTH = 100;

    /** Words tried on their own when the whole term matches nothing. */
    private const MAX_FALLBACK_TERMS = 4;
    private const MIN_TERM_LENGTH = 3;

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
            'name' => 'Product search',
            'description' => 'Finds products of this shop by name and returns each with its price (gross, in the shop currency), availability and product page URL. Send the search term as text, or as a data part {"query": "...", "limit": 10, "offset": 0, "locale": "..."}.',
            'tags' => ['commerce', 'catalogue', 'search', 'products'],
            'examples' => ['Robusto', '{"query": "Robusto", "limit": 5}'],
            'inputModes' => ['text/plain', 'application/json'],
            'outputModes' => ['application/json', 'text/plain'],
        ];
    }

    public function arguments(SkillInput $input): array
    {
        $query = $input->data['query'] ?? $input->text;
        if (!\is_string($query)) {
            throw A2aError::invalidParams('message.parts.data.query', 'query must be a string');
        }
        $query = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, self::MAX_QUERY_LENGTH);
        // An empty term would be `LIKE '%%'`: every product in the channel.
        if ('' === $query) {
            throw A2aError::invalidParams('message.parts', 'a search term is required: send it as a text part, or as {"query": "..."} in a data part');
        }

        $limit = $input->data['limit'] ?? self::DEFAULT_LIMIT;
        if (!\is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw A2aError::invalidParams('message.parts.data.limit', \sprintf('limit must be an integer from 1 to %d', self::MAX_LIMIT));
        }

        $offset = $input->data['offset'] ?? 0;
        if (!\is_int($offset) || $offset < 0 || $offset >= self::MAX_RESULTS) {
            throw A2aError::invalidParams('message.parts.data.offset', \sprintf('offset must be an integer from 0 to %d', self::MAX_RESULTS - 1));
        }

        return ['query' => $query, 'limit' => $limit, 'offset' => $offset];
    }

    public function run(array $arguments, AgentContext $context, string $localeCode): SkillResult
    {
        $query = (string) ($arguments['query'] ?? '');
        $limit = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, min(self::MAX_RESULTS - 1, (int) ($arguments['offset'] ?? 0)));
        // Paging never reaches past the window, whatever the reference says.
        $limit = min($limit, self::MAX_RESULTS - $offset);

        // One row more than the page tells whether there is a next one.
        $found = $this->products->search($context->channel, $localeCode, $query, $offset, $limit + 1);
        $hasMore = \count($found) > $limit && $offset + $limit < self::MAX_RESULTS;
        $products = $this->present(\array_slice($found, 0, $limit), $context, $localeCode, $limit);
        $matchedTerms = [] === $found ? [] : [$query];

        if ([] === $found && 0 === $offset) {
            foreach ($this->fallbackTerms($query) as $term) {
                $hits = $this->present($this->products->search($context->channel, $localeCode, $term, 0, $limit), $context, $localeCode, $limit);
                if ([] === $hits) {
                    continue;
                }
                $matchedTerms[] = $term;
                foreach ($hits as $code => $product) {
                    $products[$code] ??= $product;
                }
                if (\count($products) >= $limit) {
                    break;
                }
            }
            $products = \array_slice($products, 0, $limit, true);
        }

        $products = array_values($products);
        $data = [
            'query' => $query,
            'locale' => $localeCode,
            'products' => $products,
            'hasMore' => $hasMore,
        ];
        if ($hasMore) {
            $data['nextOffset'] = $offset + $limit;
        }
        if ([] !== $matchedTerms && [$query] !== $matchedTerms) {
            $data['matchedTerms'] = $matchedTerms;
        }

        return new SkillResult(
            summary: $this->summary($query, \count($products), $matchedTerms, $hasMore),
            artifactName: 'product-search-results',
            data: $data,
            text: $this->text($products),
        );
    }

    /**
     * @param iterable<\Sylius\Component\Core\Model\ProductInterface> $found
     *
     * @return array<string, array<string, mixed>> keyed by product code
     */
    private function present(iterable $found, AgentContext $context, string $localeCode, int $limit): array
    {
        $products = [];
        foreach ($found as $product) {
            $presented = $this->presenter->summary($product, $context, $localeCode);
            if (null !== $presented) {
                $products[(string) $presented['code']] = $presented;
            }
        }

        return \array_slice($products, 0, $limit, true);
    }

    /** @return list<string> */
    private function fallbackTerms(string $query): array
    {
        $terms = [];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, \PREG_SPLIT_NO_EMPTY);
        foreach (false === $words ? [] : $words as $word) {
            if (mb_strlen($word) >= self::MIN_TERM_LENGTH) {
                $terms[mb_strtolower($word)] ??= $word;
            }
        }
        $terms = array_values($terms);
        // A single word that is the query itself was already searched.
        if ([$query] === $terms) {
            return [];
        }
        usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return \array_slice($terms, 0, self::MAX_FALLBACK_TERMS);
    }

    /** @param list<string> $matchedTerms */
    private function summary(string $query, int $count, array $matchedTerms, bool $hasMore): string
    {
        if (0 === $count) {
            return \sprintf('No products found for "%s".', $query);
        }

        $found = \sprintf('%d %s', $count, 1 === $count ? 'product' : 'products');
        if ([$query] !== $matchedTerms) {
            return \sprintf('No product name contains "%s"; %s match %s.', $query, $found, implode(', ', array_map(static fn (string $term): string => '"'.$term.'"', $matchedTerms)));
        }

        return \sprintf('Found %s for "%s"%s.', $found, $query, $hasMore ? '; more are available with a higher offset' : '');
    }

    /** @param list<array<string, mixed>> $products */
    private function text(array $products): string
    {
        $lines = [];
        foreach ($products as $index => $product) {
            $lines[] = ($index + 1).'. '.$this->presenter->line($product);
        }

        return implode("\n", $lines);
    }
}
