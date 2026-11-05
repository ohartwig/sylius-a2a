<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\Agent\A2aEndpointProvider;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;

/**
 * What this provider announces is not an implementation detail: `agent-card.json`
 * under `/.well-known/` is where another agent looks, without being told, and
 * the discovery layer projects whatever is yielded here straight into what the
 * shop advertises. A path typo is not a failing request — it is an endpoint
 * nobody finds, and nothing in the shop notices.
 *
 * So the value and the protocol tag are pinned literally rather than through a
 * constant, on purpose: a test that reads the same constant as the code agrees
 * with any change, including the wrong one.
 */
final class A2aEndpointProviderTest extends TestCase
{
    /**
     * Without an A2A interface the card answers 404. Announcing it anyway would
     * put that 404 into llms.txt, the Link headers and the readiness check.
     */
    public function testItAnnouncesNothingWhileTheCardIsNotServed(): void
    {
        $endpoints = (new A2aEndpointProvider())->getAgentEndpoints($this->context());

        self::assertSame([], iterator_to_array($endpoints));
    }

    public function testItAnnouncesTheWellKnownAgentCard(): void
    {
        $endpoints = iterator_to_array($this->endpoints());

        self::assertCount(1, $endpoints);
        self::assertSame('/.well-known/agent-card.json', $endpoints[0]->path);
    }

    public function testTheEndpointIsTaggedAsA2a(): void
    {
        // The discovery document groups endpoints by protocol. Mislabel it and
        // an A2A client filtering for its own protocol stops seeing the shop.
        self::assertSame('a2a', $this->endpoints()->current()->protocol);
    }

    public function testTheEndpointCarriesATitleAndDescriptionForHumans(): void
    {
        $endpoint = $this->endpoints()->current();

        self::assertNotSame('', $endpoint->title);
        self::assertNotSame('', $endpoint->description);
    }

    public function testItYieldsRatherThanBuildingAList(): void
    {
        // The interface returns iterable and the discovery layer may stop
        // early. Returning an array would still satisfy the signature, so this
        // records the intent rather than assuming it.
        self::assertInstanceOf(\Generator::class, $this->endpoints());
    }

    public function testItIsAnEndpointProviderSoTheRegistryPicksItUp(): void
    {
        // Wiring is by interface, not by name. Drop the interface and the
        // provider is simply never asked — no error anywhere.
        self::assertInstanceOf(AgentEndpointProviderInterface::class, new A2aEndpointProvider(true));
    }

    public function testEveryYieldedEntryIsAnAgentEndpoint(): void
    {
        foreach ($this->endpoints() as $endpoint) {
            self::assertInstanceOf(AgentEndpoint::class, $endpoint);
        }
    }

    /**
     * The RFC 9727 api-catalog (and the Link headers built from it) lists the
     * JSON-RPC endpoint with the card as its service description — exactly
     * while both are served.
     */
    public function testTheApiCatalogListsTheEndpointDescribedByTheCard(): void
    {
        $entries = iterator_to_array((new A2aEndpointProvider(true, true))->getApiCatalogEntries($this->context()));

        self::assertCount(1, $entries);
        self::assertInstanceOf(ApiCatalogEntry::class, $entries[0]);
        self::assertSame([
            'anchor' => 'https://shop.example.com/api/agent/a2a',
            'service-desc' => [['href' => 'https://shop.example.com/.well-known/agent-card.json', 'type' => 'application/json']],
            'title' => 'A2A agent (JSON-RPC)',
        ], $entries[0]->toArray());
        self::assertInstanceOf(ApiCatalogEntryProviderInterface::class, new A2aEndpointProvider());
    }

    public function testTheApiCatalogListsNothingWithoutTheEndpoint(): void
    {
        // A card that names only interfaces served elsewhere: those are the
        // other component's to list.
        self::assertSame([], iterator_to_array((new A2aEndpointProvider(true, false))->getApiCatalogEntries($this->context())));
        self::assertSame([], iterator_to_array((new A2aEndpointProvider(false, true))->getApiCatalogEntries($this->context())));
        self::assertSame([], iterator_to_array((new A2aEndpointProvider())->getApiCatalogEntries($this->context())));
    }

    private function endpoints(): \Generator
    {
        $endpoints = (new A2aEndpointProvider(true))->getAgentEndpoints($this->context());
        self::assertInstanceOf(\Generator::class, $endpoints);

        return $endpoints;
    }

    private function context(): AgentContext
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getName')->willReturn('Example Shop');

        return new AgentContext(
            channel: $channel,
            baseUrl: 'https://shop.example.com',
            localeCode: 'de_DE',
        );
    }
}
