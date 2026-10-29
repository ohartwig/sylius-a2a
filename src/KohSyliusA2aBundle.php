<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Publishes the A2A Agent Card.
 */
final class KohSyliusA2aBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_a2a';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('version')->defaultValue('1.0.0')->end()
                ->scalarNode('description')
                    ->defaultNull()
                    ->info('Defaults to "Agent interface for <channel name>."')
                ->end()
                ->scalarNode('transport_path')
                    ->defaultNull()
                    ->info('Ignored. The card names no interface until the shop serves A2A.')
                    ->setDeprecated('koh/sylius-a2a', '1.2', 'The "%node%" option is ignored: the card names no interface until the shop serves A2A (AUD-066-F01). Remove it.')
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_a2a.version', (string) ($config['version'] ?? '1.0.0'));
        $builder->setParameter('koh_a2a.description', $config['description'] ?? null);

        $container->import('../config/services.php');
    }
}
