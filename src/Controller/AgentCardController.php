<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the A2A Agent Card at /.well-known/agent-card.json.
 *
 * Skills come from the shared registry rather than a list in this file. Two
 * other documents project the same skills — the agent-skills index and the MCP
 * server card — and hand-maintaining three copies is how they end up disagreeing
 * about what the shop can do.
 */
final class AgentCardController
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
        private readonly string $transportPath = '/_mcp',
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $skills = $this->skills->all($context);
        $name = $context->channel->getName() ?? 'Shop';

        return $this->responder->respond([
            'name' => $name,
            'version' => $this->version,
            'description' => $this->description ?? \sprintf('Agent interface for %s.', $name),
            'url' => $context->baseUrl,
            'preferredTransport' => 'JSONRPC',
            'supportedInterfaces' => [[
                'url' => $context->url($this->transportPath),
                'transport' => 'JSONRPC',
            ]],
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'stateTransitionHistory' => false,
            ],
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['application/json'],
            'skills' => array_map($this->toA2aSkill(...), $skills),
        ], request: $request);
    }

    /** @return array<string, mixed> */
    private function toA2aSkill(AgentSkill $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'tags' => $skill->tags,
            'inputModes' => $skill->inputModes,
            'outputModes' => $skill->outputModes,
        ];
    }
}
