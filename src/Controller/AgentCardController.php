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
 * Serves the A2A Agent Card at /.well-known/agent-card.json, or nothing.
 *
 * Since A2A 1.0, `supportedInterfaces` is a required field: a card is a
 * description of where to send `SendMessage`, not a brochure. This package
 * serves no A2A transport, so the card is only published when the operator
 * names an interface that does — served by some other component. Without one
 * the path answers 404. A card with no interface is invalid, and a card that
 * names the MCP endpoint as an A2A interface steers agents into speaking A2A
 * to an MCP server that also wants an admin-delegated token (AUD-066-F01).
 *
 * Skills come from the shared registry rather than a list in this file. Two
 * other documents project the same skills — the agent-skills index and the MCP
 * server card — and hand-maintaining three copies is how they end up disagreeing
 * about what the shop can do.
 */
final class AgentCardController
{
    /**
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     */
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
        private readonly array $interfaces = [],
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ([] === $this->interfaces) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $context = $this->contextFactory->create();
        $name = $context->channel->getName() ?? 'Shop';

        return $this->responder->respond([
            'name' => $name,
            'description' => $this->description ?? \sprintf('Agent interface for %s.', $name),
            'supportedInterfaces' => array_map(
                static fn (array $interface): array => [
                    'url' => self::absolute($interface['url'], $context->baseUrl),
                    'protocolBinding' => $interface['protocol_binding'],
                    'protocolVersion' => $interface['protocol_version'],
                ],
                $this->interfaces,
            ),
            'version' => $this->version,
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
            ],
            'defaultInputModes' => ['text/plain', 'application/json'],
            'defaultOutputModes' => ['application/json'],
            'skills' => array_map($this->toA2aSkill(...), $this->skills->all($context)),
        ], request: $request);
    }

    /** A path is resolved against the channel's base URL; an absolute URL is kept. */
    private static function absolute(string $url, string $baseUrl): string
    {
        if (!str_starts_with($url, '/')) {
            return $url;
        }

        return rtrim($baseUrl, '/').$url;
    }

    /** @return array<string, mixed> */
    private function toA2aSkill(AgentSkill $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            // `tags` is required. A skill registered without any still gets one
            // rather than an empty list a strict validator rejects.
            'tags' => [] !== $skill->tags ? $skill->tags : [$skill->id],
            'inputModes' => $skill->inputModes,
            'outputModes' => $skill->outputModes,
        ];
    }
}
