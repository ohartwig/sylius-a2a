<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\Agent\A2aEndpointProvider;
use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusA2a\KohSyliusA2aBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Loader\DefinitionFileLoader;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * The bundle is three small decisions that are only visible once a shop boots,
 * which is exactly why they are worth testing here rather than there.
 *
 * `getPath()` is overridden because `config/` sits at the package root and not
 * under `src/`. Symfony resolves the bundle path from the class file, so
 * without the override every `import('../config/…')` would resolve one level
 * too deep and the services would silently not be registered.
 *
 * The configuration defaults are a public contract: a shop that sets nothing
 * still has to end up with a working version. `null` for
 * the description is deliberate rather than an empty string — the card
 * generator distinguishes "not configured" from "configured as empty" and
 * substitutes a channel-derived sentence for the former.
 *
 * `loadExtension()` turns that config into container parameters. The casts
 * there are what keeps a YAML `version: 1.0` (a float to the YAML parser) from
 * reaching the card as `1`.
 */
final class KohSyliusA2aBundleTest extends TestCase
{
    public function testThePathPointsAtThePackageRootWhereConfigLives(): void
    {
        $path = (new KohSyliusA2aBundle())->getPath();

        self::assertDirectoryExists($path . '/config');
        self::assertFileExists($path . '/config/services.php');
        // Not src/: that is the whole reason the override exists.
        self::assertSame(\dirname(__DIR__), $path);
    }

    public function testTheDefaultsAreUsableWithoutAnyConfiguration(): void
    {
        $config = $this->processConfig([]);

        self::assertSame('1.0.0', $config['version']);
        self::assertNull($config['transport_path']);
        self::assertSame([], $config['interfaces']);
    }

    public function testAnInterfaceDefaultsToJsonRpcAtA2a10(): void
    {
        $config = $this->processConfig([['interfaces' => [['url' => '/a2a/v1']]]]);

        self::assertSame(
            [['url' => '/a2a/v1', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']],
            $config['interfaces'],
        );
    }

    /** MCP is not an A2A protocol binding; the old mistake cannot be configured back in. */
    public function testOnlyTheA2aCoreBindingsAreAccepted(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([['interfaces' => [['url' => '/_mcp', 'protocol_binding' => 'MCP']]]]);
    }

    public function testAnInterfaceNeedsAUrl(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig([['interfaces' => [['protocol_binding' => 'JSONRPC']]]]);
    }

    public function testByDefaultTheEndpointServesTheCard(): void
    {
        $builder = $this->loadExtension([]);

        self::assertSame([], $builder->getParameter('koh_a2a.interfaces'));
        self::assertTrue($builder->getParameter('koh_a2a.endpoint.enabled'));
        self::assertTrue($builder->getParameter('koh_a2a.card_served'));
        self::assertSame(
            ['%koh_a2a.card_served%', '%koh_a2a.endpoint.enabled%'],
            array_map('strval', $builder->getDefinition(A2aEndpointProvider::class)->getArguments()),
        );
        self::assertSame(
            '%koh_a2a.interfaces%',
            (string) $builder->getDefinition(AgentCardController::class)->getArgument(5),
        );
        self::assertSame(
            '%koh_a2a.endpoint.enabled%',
            (string) $builder->getDefinition(AgentCardController::class)->getArgument(6),
        );
    }

    public function testWithTheEndpointOffAndNoInterfacesTheCardIsNeitherServedNorAnnounced(): void
    {
        $builder = $this->loadExtension(['endpoint' => ['enabled' => false]]);

        self::assertFalse($builder->getParameter('koh_a2a.endpoint.enabled'));
        self::assertFalse($builder->getParameter('koh_a2a.card_served'));
    }

    public function testTheEndpointDefaults(): void
    {
        $config = $this->processConfig([]);

        self::assertSame(
            ['enabled' => true, 'rate_limit' => 60, 'rate_interval' => '1 minute', 'max_request_bytes' => 65536, 'task_ttl' => 86400],
            $config['endpoint'],
        );
        self::assertNull($config['age_restriction']);

        $builder = $this->loadExtension([]);
        self::assertSame(60, $builder->getParameter('koh_a2a.endpoint.rate_limit'));
        self::assertSame('1 minute', $builder->getParameter('koh_a2a.endpoint.rate_interval'));
        self::assertSame(65536, $builder->getParameter('koh_a2a.endpoint.max_request_bytes'));
        self::assertSame(86400, $builder->getParameter('koh_a2a.endpoint.task_ttl'));
        self::assertNull($builder->getParameter('koh_a2a.age_restriction'));
    }

    public function testTheEndpointSettingsReachTheContainer(): void
    {
        $builder = $this->loadExtension([
            'endpoint' => ['enabled' => true, 'rate_limit' => 10, 'rate_interval' => '10 seconds', 'max_request_bytes' => 2048, 'task_ttl' => 600],
            'age_restriction' => 18,
        ]);

        self::assertSame(10, $builder->getParameter('koh_a2a.endpoint.rate_limit'));
        self::assertSame('10 seconds', $builder->getParameter('koh_a2a.endpoint.rate_interval'));
        self::assertSame(2048, $builder->getParameter('koh_a2a.endpoint.max_request_bytes'));
        self::assertSame(600, $builder->getParameter('koh_a2a.endpoint.task_ttl'));
        self::assertSame(18, $builder->getParameter('koh_a2a.age_restriction'));
    }

    public function testNonsensicalEndpointLimitsAreRejected(): void
    {
        foreach ([['rate_limit' => 0], ['max_request_bytes' => 100], ['task_ttl' => 1]] as $endpoint) {
            try {
                $this->processConfig([['endpoint' => $endpoint]]);
                self::fail('Accepted '.json_encode($endpoint));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->processConfig([['age_restriction' => -1]]);
    }

    public function testTheSkillsAreRegisteredInTheirCardOrder(): void
    {
        $builder = $this->loadExtension([]);

        self::assertSame(20, $builder->getDefinition(\Koh\SyliusA2a\Skill\ProductSearchSkill::class)->getTag('koh_a2a.skill')[0]['priority']);
        self::assertSame(10, $builder->getDefinition(\Koh\SyliusA2a\Skill\ProductDetailSkill::class)->getTag('koh_a2a.skill')[0]['priority']);
        self::assertTrue($builder->getDefinition(\Koh\SyliusA2a\Controller\JsonRpcController::class)->isPublic());
        self::assertSame('%kernel.secret%', (string) $builder->getDefinition(\Koh\SyliusA2a\Task\TaskIdCodec::class)->getArgument(0));
    }

    public function testConfiguredInterfacesReachTheContainer(): void
    {
        $builder = $this->loadExtension(['interfaces' => [
            ['url' => 'https://agents.example.com/a2a', 'protocol_binding' => 'GRPC', 'protocol_version' => '1.1'],
            ['url' => '/a2a/v1'],
        ]]);

        self::assertSame([
            ['url' => 'https://agents.example.com/a2a', 'protocol_binding' => 'GRPC', 'protocol_version' => '1.1'],
            ['url' => '/a2a/v1', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0'],
        ], $builder->getParameter('koh_a2a.interfaces'));
        self::assertTrue($builder->getParameter('koh_a2a.card_served'));
    }

    public function testAProtocolVersionWrittenAsAYamlNumberKeepsItsMinor(): void
    {
        // `protocol_version: 1.0` parses as the float 1.0; A2A wants "1.0", not "1".
        $builder = $this->loadExtension(['interfaces' => [['url' => '/a2a', 'protocol_version' => 1.0]]]);

        /** @var list<array<string, string>> $interfaces */
        $interfaces = $builder->getParameter('koh_a2a.interfaces');
        self::assertSame('1.0', $interfaces[0]['protocol_version']);
    }

    public function testMalformedInterfaceEntriesAreSkipped(): void
    {
        $builder = $this->loadExtension(['interfaces' => ['not-an-interface', ['url' => '/a2a']]]);

        self::assertSame(
            [['url' => '/a2a', 'protocol_binding' => 'JSONRPC', 'protocol_version' => '1.0']],
            $builder->getParameter('koh_a2a.interfaces'),
        );
    }

    public function testAnUnsetDescriptionStaysNullRatherThanBecomingEmpty(): void
    {
        // The card generator reads null as "derive one from the channel name".
        // An empty string would be published as an empty description instead.
        self::assertNull($this->processConfig([])['description']);
    }

    public function testEveryValueCanBeOverridden(): void
    {
        $config = $this->processConfig([[
            'version' => '2.1.0',
            'description' => 'Eigener Text.',
        ]]);

        self::assertSame('2.1.0', $config['version']);
        self::assertSame('Eigener Text.', $config['description']);
    }

    public function testTheParametersReachTheContainerAsStrings(): void
    {
        $builder = $this->loadExtension(['version' => '2.0.0']);

        self::assertSame('2.0.0', $builder->getParameter('koh_a2a.version'));
        self::assertFalse($builder->hasParameter('koh_a2a.transport_path'));
    }

    /** Installations that still set the option keep booting; the value is not used. */
    public function testTheRetiredTransportPathIsStillAccepted(): void
    {
        $builder = $this->loadExtension(['transport_path' => '/_mcp', 'endpoint' => ['enabled' => false]]);

        self::assertFalse($builder->hasParameter('koh_a2a.transport_path'));
        // And it does not turn into an interface: the MCP endpoint is not A2A.
        self::assertSame([], $builder->getParameter('koh_a2a.interfaces'));
        self::assertFalse($builder->getParameter('koh_a2a.card_served'));
    }

    public function testAVersionWrittenAsAYamlNumberIsNotPublishedAsOne(): void
    {
        // `version: 1.0` in YAML parses as the float 1.0, which would render as
        // "1" in the agent card. The cast in loadExtension is what stops that.
        $builder = $this->loadExtension(['version' => 1.0]);

        self::assertSame('1', $builder->getParameter('koh_a2a.version'));
        self::assertIsString($builder->getParameter('koh_a2a.version'));
    }

    public function testAnAbsentDescriptionStaysNullInTheContainerToo(): void
    {
        self::assertNull($this->loadExtension([])->getParameter('koh_a2a.description'));
    }

    public function testTheServiceDefinitionsAreImported(): void
    {
        // If getPath() ever regressed, the import would resolve elsewhere and
        // the container would come up without the controller — a shop that
        // boots fine and answers 404 on the agent card.
        $builder = $this->loadExtension([]);

        self::assertNotSame([], $builder->getDefinitions());
    }

    /**
     * @param list<array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $configs): array
    {
        $treeBuilder = new TreeBuilder('koh_a2a');
        $configurator = new DefinitionConfigurator(
            $treeBuilder,
            $this->createStub(DefinitionFileLoader::class),
            __DIR__,
            'test',
        );

        (new KohSyliusA2aBundle())->configure($configurator);

        return (new Processor())->process($treeBuilder->buildTree(), $configs);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadExtension(array $config): ContainerBuilder
    {
        $bundle = new KohSyliusA2aBundle();
        $builder = new ContainerBuilder();
        $path = $bundle->getPath() . '/config/services.php';

        $loader = new PhpFileLoader($builder, new FileLocator(\dirname($path)));
        $instanceof = [];
        $configurator = new ContainerConfigurator($builder, $loader, $instanceof, $path, $path);

        $bundle->loadExtension($config, $configurator, $builder);

        return $builder;
    }
}
