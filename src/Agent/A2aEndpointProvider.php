<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;

/**
 * Announces the agent card to the discovery layer (llms.txt, Link headers,
 * the readiness check) — but only while the card is served. Without an A2A
 * interface the card answers 404, and announcing it would send every agent
 * that reads the discovery documents to that 404.
 */
final class A2aEndpointProvider implements AgentEndpointProviderInterface
{
    public function __construct(private readonly bool $cardServed = false)
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        if (!$this->cardServed) {
            return;
        }

        yield new AgentEndpoint(
            path: '/.well-known/agent-card.json',
            title: 'A2A Agent Card',
            protocol: 'a2a',
            description: 'What this shop can do, for agent-to-agent discovery.',
        );
    }
}
