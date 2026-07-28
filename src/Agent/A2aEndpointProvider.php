<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;

final class A2aEndpointProvider implements AgentEndpointProviderInterface
{
    public function getAgentEndpoints(AgentContext $context): iterable
    {
        yield new AgentEndpoint(
            path: '/.well-known/agent-card.json',
            title: 'A2A Agent Card',
            protocol: 'a2a',
            description: 'What this shop can do, for agent-to-agent discovery.',
        );
    }
}
