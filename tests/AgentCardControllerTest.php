<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Model\AgentSkill;
use Koh\SyliusAgentContracts\Provider\AgentSkillProviderInterface;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AgentCardControllerTest extends TestCase
{
    /**
     * A2A 1.0 requires `supportedInterfaces`. This package serves no A2A
     * transport, so without a configured interface there is no valid card to
     * publish — and an invalid one, or one naming the MCP endpoint, is worse
     * than none (AUD-066-F01).
     */
    public function testWithoutAnInterfaceTheCardIsNotServed(): void
    {
        $response = $this->response(interfaces: []);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    /** The field set A2A 1.0 marks as required, and nothing the 0.3 card carried at the top level. */
    public function testCardCarriesExactlyTheA2a10Fields(): void
    {
        $card = $this->card();

        self::assertSame([
            'name',
            'description',
            'supportedInterfaces',
            'version',
            'capabilities',
            'defaultInputModes',
            'defaultOutputModes',
            'skills',
        ], array_keys($card));
        self::assertSame('Example Shop', $card['name']);
        self::assertSame('1.0.0', $card['version']);
        self::assertSame('Agent interface for Example Shop.', $card['description']);
        self::assertSame(['streaming' => false, 'pushNotifications' => false], $card['capabilities']);
        self::assertSame(['text/plain', 'application/json'], $card['defaultInputModes']);
        self::assertSame(['application/json'], $card['defaultOutputModes']);
    }

    public function testAnInterfacePathIsResolvedAgainstTheChannelBaseUrl(): void
    {
        $card = $this->card();

        self::assertSame([[
            'url' => 'https://shop.example.com/a2a/v1',
            'protocolBinding' => 'JSONRPC',
            'protocolVersion' => '1.0',
        ]], $card['supportedInterfaces']);
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        $card = $this->card(baseUrl: 'https://shop.example.com/');

        self::assertSame('https://shop.example.com/a2a/v1', $card['supportedInterfaces'][0]['url']);
    }

    public function testInterfacesKeepTheirOrderAndAbsoluteUrls(): void
    {
        $card = $this->card(interfaces: [
            ['url' => 'https://agents.example.com/a2a', 'protocol_binding' => 'HTTP+JSON', 'protocol_version' => '1.1'],
            ['url' => '/a2a/v1', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0'],
        ]);

        self::assertSame([
            ['url' => 'https://agents.example.com/a2a', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.1'],
            ['url' => 'https://shop.example.com/a2a/v1', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0'],
        ], $card['supportedInterfaces']);
    }

    public function testTheCardIsAServedDiscoveryDocument(): void
    {
        $response = $this->response();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
    }

    public function testSkillsCarryIdNameAndDescription(): void
    {
        $skill = $this->card()['skills'][0];

        self::assertSame('product-search', $skill['id']);
        self::assertSame('Product Search', $skill['name']);
        self::assertSame('Search the catalogue by keyword.', $skill['description']);
        self::assertSame(['text/plain'], $skill['inputModes']);
        self::assertSame(['application/json'], $skill['outputModes']);
    }

    /** `tags` is required on a skill; one registered without tags gets its id. */
    public function testASkillWithoutTagsIsTaggedWithItsId(): void
    {
        $skill = $this->card()['skills'][0];

        self::assertSame(['product-search'], $skill['tags']);
    }

    public function testASkillsOwnTagsAreKept(): void
    {
        $card = $this->card([
            $this->provider(new AgentSkill('product-search', 'Product Search', 'a', tags: ['catalogue', 'search'])),
        ]);

        self::assertSame(['catalogue', 'search'], $card['skills'][0]['tags']);
    }

    /**
     * The card projects the shared registry, so a shop that installs another
     * skill-bearing bundle advertises it here without this package changing.
     */
    public function testEverySkillInTheRegistryIsProjected(): void
    {
        $card = $this->card([
            $this->provider(new AgentSkill('product-search', 'Product Search', 'a')),
            $this->provider(new AgentSkill('order-status', 'Order Status', 'b')),
        ]);

        self::assertSame(['order-status', 'product-search'], array_column($card['skills'], 'id'));
    }

    /** With an interface but no skills the card is still served; `skills` is required, so it is an empty list. */
    public function testCardIsServedEvenWithoutSkills(): void
    {
        $card = $this->card([]);

        self::assertSame([], $card['skills']);
        self::assertSame('Example Shop', $card['name']);
    }

    public function testDescriptionCanBeOverridden(): void
    {
        $card = $this->card(description: 'Tobacco, shipped from Germany.');

        self::assertSame('Tobacco, shipped from Germany.', $card['description']);
    }

    private const INTERFACES = [
        ['url' => '/a2a/v1', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0'],
    ];

    /**
     * @param list<AgentSkillProviderInterface>|null                                         $providers
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     *
     * @return array<string, mixed>
     */
    private function card(
        ?array $providers = null,
        ?string $description = null,
        array $interfaces = self::INTERFACES,
        string $baseUrl = 'https://shop.example.com',
    ): array {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) $this->response($providers, $description, $interfaces, $baseUrl)->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @param list<AgentSkillProviderInterface>|null                                         $providers
     * @param list<array{url: string, protocol_binding: string, protocol_version: string}> $interfaces
     */
    private function response(
        ?array $providers = null,
        ?string $description = null,
        array $interfaces = self::INTERFACES,
        string $baseUrl = 'https://shop.example.com',
    ): Response {
        $controller = new AgentCardController(
            new AgentSkillRegistry($providers ?? [$this->provider(
                new AgentSkill('product-search', 'Product Search', 'Search the catalogue by keyword.'),
            )]),
            $this->contextFactory($baseUrl),
            new AgentJsonResponder(),
            '1.0.0',
            $description,
            $interfaces,
        );

        return $controller(new Request());
    }

    private function provider(AgentSkill $skill): AgentSkillProviderInterface
    {
        return new class($skill) implements AgentSkillProviderInterface {
            public function __construct(private AgentSkill $skill)
            {
            }

            public function getAgentSkills(AgentContext $context): iterable
            {
                yield $this->skill;
            }
        };
    }

    private function contextFactory(string $baseUrl): AgentContextFactoryInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Example Shop');

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, $baseUrl, 'de_DE', ['de_DE']),
        );

        return $factory;
    }
}
