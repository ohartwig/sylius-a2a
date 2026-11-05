<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Agent;

use Koh\SyliusA2a\Controller\JsonRpcController;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Announces the agent card to the discovery layer (llms.txt, the readiness
 * check) and the A2A endpoint to the RFC 9727 api-catalog and its Link headers
 * — but only while the card is served. Without an A2A interface the card
 * answers 404, and announcing it would send every agent that reads the
 * discovery documents to that 404.
 */
final class A2aEndpointProvider implements AgentEndpointProviderInterface, ApiCatalogEntryProviderInterface
{
    public function __construct(
        private readonly bool $cardServed = false,
        private readonly bool $endpointEnabled = false,
    ) {
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

    /**
     * The endpoint itself, described by the card. Only for the endpoint this
     * package serves: an interface served elsewhere is that component's to
     * list.
     */
    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        if (!$this->cardServed || !$this->endpointEnabled) {
            return;
        }

        yield new ApiCatalogEntry(
            anchor: $context->url(JsonRpcController::PATH),
            serviceDesc: [new LinkTarget($context->url('/.well-known/agent-card.json'), 'application/json')],
            title: 'A2A agent (JSON-RPC)',
        );
    }
}
