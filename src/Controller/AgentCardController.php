<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Controller;

use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the A2A 1.0 Agent Card at /.well-known/agent-card.json, or nothing.
 *
 * Since A2A 1.0, `supportedInterfaces` is a required field: a card is a
 * description of where to send `SendMessage`, not a brochure. The card names
 * this package's own JSON-RPC endpoint when it is enabled, followed by any
 * further interfaces the operator configured for the same agent. With neither
 * the path answers 404. A card that names the MCP endpoint as an A2A interface
 * steers agents into speaking A2A to an MCP server that also wants an
 * admin-delegated token (AUD-066-F01), so that cannot be configured.
 *
 * The skills are the ones the endpoint executes — not the shared skill
 * registry, which also holds skills other interfaces serve (an ACP feed, MCP
 * tools). A card that names a skill SendMessage cannot run is the same lie as
 * a card without an endpoint.
 */
final class AgentCardController
{
    /**
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     */
    public function __construct(
        private readonly A2aMethods $methods,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '1.0.0',
        private readonly ?string $description = null,
        private readonly array $interfaces = [],
        private readonly bool $endpointEnabled = true,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $interfaces = $this->endpointEnabled
            ? [[
                'url' => JsonRpcController::PATH,
                'protocol_binding' => 'JSONRPC',
                'protocol_version' => A2aMethods::PROTOCOL_VERSION,
            ], ...$this->interfaces]
            : $this->interfaces;
        if ([] === $interfaces) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $context = $this->contextFactory->create();
        $name = $context->channel->getName() ?? 'Shop';

        return $this->responder->respond([
            'name' => $name,
            'description' => $this->description ?? \sprintf('Agent interface for %s: searches the catalogue and returns products with prices, availability and product page links.', $name),
            'supportedInterfaces' => array_map(
                static fn (array $interface): array => [
                    'url' => self::absolute($interface['url'], $context->baseUrl),
                    'protocolBinding' => $interface['protocol_binding'],
                    'protocolVersion' => $interface['protocol_version'],
                ],
                $interfaces,
            ),
            'provider' => [
                'organization' => $name,
                'url' => self::absolute('/', $context->baseUrl),
            ],
            'version' => $this->version,
            'capabilities' => [
                'streaming' => false,
                'pushNotifications' => false,
                'extendedAgentCard' => false,
            ],
            'defaultInputModes' => A2aMethods::INPUT_MODES,
            'defaultOutputModes' => A2aMethods::OUTPUT_MODES,
            'skills' => array_map(static fn ($skill): array => $skill->card(), $this->methods->skills()),
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
}
