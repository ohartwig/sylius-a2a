<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\Agent\A2aEndpointProvider;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
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
        self::assertInstanceOf(AgentEndpointProviderInterface::class, new A2aEndpointProvider());
    }

    public function testEveryYieldedEntryIsAnAgentEndpoint(): void
    {
        foreach ($this->endpoints() as $endpoint) {
            self::assertInstanceOf(AgentEndpoint::class, $endpoint);
        }
    }

    private function endpoints(): \Generator
    {
        $endpoints = (new A2aEndpointProvider())->getAgentEndpoints($this->context());
        self::assertInstanceOf(\Generator::class, $endpoints);

        return $endpoints;
    }

    private function context(): AgentContext
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getName')->willReturn('Tabak Budde');

        return new AgentContext(
            channel: $channel,
            baseUrl: 'https://shop.example.com',
            localeCode: 'de_DE',
        );
    }
}
