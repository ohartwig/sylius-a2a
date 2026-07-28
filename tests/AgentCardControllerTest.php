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

final class AgentCardControllerTest extends TestCase
{
    public function testCardCarriesTheRequiredFields(): void
    {
        $card = $this->card();

        self::assertSame('Tabak Budde', $card['name']);
        self::assertSame('1.0.0', $card['version']);
        self::assertArrayHasKey('description', $card);
        self::assertArrayHasKey('capabilities', $card);
    }

    public function testInterfacePointsAtTheTransport(): void
    {
        $interface = $this->card()['supportedInterfaces'][0];

        self::assertSame('https://shop.example.com/_mcp', $interface['url']);
        self::assertSame('JSONRPC', $interface['transport']);
    }

    public function testSkillsCarryIdNameAndDescription(): void
    {
        $skill = $this->card()['skills'][0];

        self::assertSame('product-search', $skill['id']);
        self::assertSame('Product Search', $skill['name']);
        self::assertSame('Search the catalogue by keyword.', $skill['description']);
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

    /** A shop with nothing to offer still has to answer — the card is discovery, not a capability. */
    public function testCardIsServedEvenWithoutSkills(): void
    {
        $card = $this->card([]);

        self::assertSame([], $card['skills']);
        self::assertSame('Tabak Budde', $card['name']);
    }

    public function testDescriptionCanBeOverridden(): void
    {
        $card = $this->card(description: 'Tobacco, shipped from Germany.');

        self::assertSame('Tobacco, shipped from Germany.', $card['description']);
    }

    public function testTransportPathIsConfigurable(): void
    {
        $card = $this->card(transportPath: '/api/agent');

        self::assertSame('https://shop.example.com/api/agent', $card['supportedInterfaces'][0]['url']);
    }

    /**
     * @param list<AgentSkillProviderInterface>|null $providers
     *
     * @return array<string, mixed>
     */
    private function card(?array $providers = null, ?string $description = null, string $transportPath = '/_mcp'): array
    {
        $controller = new AgentCardController(
            new AgentSkillRegistry($providers ?? [$this->provider(
                new AgentSkill('product-search', 'Product Search', 'Search the catalogue by keyword.'),
            )]),
            $this->contextFactory(),
            new AgentJsonResponder(),
            '1.0.0',
            $description,
            $transportPath,
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $controller(new Request())->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
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

    private function contextFactory(): AgentContextFactoryInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $channel->method('getName')->willReturn('Tabak Budde');

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        return $factory;
    }
}
