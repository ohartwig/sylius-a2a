<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(AgentCardController::class)]
final class AgentCardControllerTest extends TestCase
{
    /**
     * A2A 1.0 requires `supportedInterfaces`. With the endpoint switched off
     * and no other interface configured there is no valid card to publish —
     * and an invalid one, or one naming the MCP endpoint, is worse than none
     * (AUD-066-F01).
     */
    public function testWithoutAnyInterfaceTheCardIsNotServed(): void
    {
        $response = $this->response(interfaces: [], endpointEnabled: false);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /** The field set of the A2A 1.0 AgentCard, and nothing the 0.3 card carried at the top level. */
    public function testCardCarriesTheA2a10Fields(): void
    {
        $card = $this->card();

        self::assertSame([
            'name',
            'description',
            'supportedInterfaces',
            'provider',
            'version',
            'capabilities',
            'defaultInputModes',
            'defaultOutputModes',
            'skills',
        ], array_keys($card));
        self::assertSame('Example Shop', $card['name']);
        self::assertSame('1.0.0', $card['version']);
        self::assertSame('Agent interface for Example Shop: searches the catalogue and returns products with prices, availability and product page links.', $card['description']);
        self::assertSame(['organization' => 'Example Shop', 'url' => 'https://shop.example.com/'], $card['provider']);
        self::assertSame(['streaming' => false, 'pushNotifications' => false, 'extendedAgentCard' => false], $card['capabilities']);
        self::assertSame(['text/plain', 'application/json'], $card['defaultInputModes']);
        self::assertSame(['application/json', 'text/plain'], $card['defaultOutputModes']);
    }

    public function testTheEndpointIsTheFirstAndPreferredInterface(): void
    {
        self::assertSame([[
            'url' => 'https://shop.example.com/api/agent/a2a',
            'protocolBinding' => 'JSONRPC',
            'protocolVersion' => '1.0',
        ]], $this->card()['supportedInterfaces']);
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        $card = $this->card(baseUrl: 'https://shop.example.com/');

        self::assertSame('https://shop.example.com/api/agent/a2a', $card['supportedInterfaces'][0]['url']);
        self::assertSame('https://shop.example.com/', $card['provider']['url']);
    }

    public function testConfiguredInterfacesFollowTheEndpointInTheirOrder(): void
    {
        $card = $this->card(interfaces: [
            ['url' => 'https://agents.example.com/a2a', 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.0'],
            ['url' => '/a2a/grpc', 'protocol_binding' => 'GRPC', 'protocol_version' => '1.0'],
        ]);

        self::assertSame([
            ['url' => 'https://shop.example.com/api/agent/a2a', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0'],
            ['url' => 'https://agents.example.com/a2a', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.0'],
            ['url' => 'https://shop.example.com/a2a/grpc', 'protocolBinding' => 'GRPC', 'protocolVersion' => '1.0'],
        ], $card['supportedInterfaces']);
    }

    public function testWithTheEndpointOffOnlyTheConfiguredInterfacesAreNamed(): void
    {
        $card = $this->card(
            interfaces: [['url' => 'https://agents.example.com/a2a', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']],
            endpointEnabled: false,
        );

        self::assertSame(['https://agents.example.com/a2a'], array_column($card['supportedInterfaces'], 'url'));
    }

    public function testTheCardIsAServedDiscoveryDocument(): void
    {
        $response = $this->response();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
    }

    /**
     * The skills are the endpoint's own, so the card cannot name a skill
     * SendMessage would refuse.
     */
    public function testTheSkillsAreTheOnesTheEndpointRuns(): void
    {
        $skills = $this->card()['skills'];

        self::assertSame(['product-search', 'product-detail'], array_column($skills, 'id'));
        foreach ($skills as $skill) {
            self::assertNotSame('', $skill['name']);
            self::assertNotSame('', $skill['description']);
            self::assertNotSame([], $skill['tags']);
            self::assertNotSame([], $skill['examples']);
            self::assertContains('application/json', $skill['outputModes']);
        }
    }

    public function testDescriptionCanBeOverridden(): void
    {
        $card = $this->card(description: 'Tobacco, shipped from Germany.');

        self::assertSame('Tobacco, shipped from Germany.', $card['description']);
    }

    /**
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     *
     * @return array<string, mixed>
     */
    private function card(
        ?string $description = null,
        array $interfaces = [],
        string $baseUrl = 'https://shop.example.com',
        bool $endpointEnabled = true,
    ): array {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->response($description, $interfaces, $baseUrl, $endpointEnabled)->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     */
    private function response(
        ?string $description = null,
        array $interfaces = [],
        string $baseUrl = 'https://shop.example.com',
        bool $endpointEnabled = true,
    ): Response {
        $catalogue = Catalogue::shop();
        $controller = new AgentCardController(
            $catalogue->methods(),
            $catalogue->contextFactory(baseUrl: $baseUrl),
            new AgentJsonResponder(),
            '1.0.0',
            $description,
            $interfaces,
            $endpointEnabled,
        );

        return $controller(Request::create('/.well-known/agent-card.json'));
    }
}
