<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests;

use Koh\SyliusA2a\KohSyliusA2aBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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
 * still has to end up with a working transport path and version. `null` for
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
        self::assertSame('/_mcp', $config['transport_path']);
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
            'transport_path' => '/agent',
        ]]);

        self::assertSame('2.1.0', $config['version']);
        self::assertSame('Eigener Text.', $config['description']);
        self::assertSame('/agent', $config['transport_path']);
    }

    public function testTheParametersReachTheContainerAsStrings(): void
    {
        $builder = $this->loadExtension(['version' => '2.0.0', 'transport_path' => '/agent']);

        self::assertSame('2.0.0', $builder->getParameter('koh_a2a.version'));
        self::assertSame('/agent', $builder->getParameter('koh_a2a.transport_path'));
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
