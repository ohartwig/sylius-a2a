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
                ->arrayNode('interfaces')
                    ->info('A2A endpoints the shop actually serves. Empty: the card answers 404 and is not announced.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('url')
                                ->isRequired()
                                ->cannotBeEmpty()
                                ->info('Absolute URL, or a path resolved against the channel base URL.')
                            ->end()
                            ->enumNode('protocol_binding')
                                ->values(['JSONRPC', 'GRPC', 'HTTP+JSON'])
                                ->defaultValue('JSONRPC')
                            ->end()
                            ->scalarNode('protocol_version')
                                ->defaultValue('1.0')
                                ->info('Major.Minor of the A2A version the endpoint speaks.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('transport_path')
                    ->defaultNull()
                    ->info('Ignored. Use "interfaces" to name an A2A endpoint.')
                    ->setDeprecated('koh/sylius-a2a', '1.2', 'The "%node%" option is ignored: an A2A interface is declared under "interfaces", and the MCP endpoint is not one (AUD-066-F01). Remove it.')
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
        $interfaces = self::interfaces($config['interfaces'] ?? []);
        $builder->setParameter('koh_a2a.interfaces', $interfaces);
        $builder->setParameter('koh_a2a.card_served', [] !== $interfaces);

        $container->import('../config/services.php');
    }

    /**
     * @return list<array{url: string, protocol_binding: string, protocol_version: string}>
     */
    private static function interfaces(mixed $interfaces): array
    {
        $result = [];
        foreach (\is_array($interfaces) ? $interfaces : [] as $interface) {
            if (!\is_array($interface)) {
                continue;
            }
            $result[] = [
                'url' => (string) $interface['url'],
                'protocol_binding' => (string) ($interface['protocol_binding'] ?? 'JSONRPC'),
                // `protocol_version: 1.0` in YAML is a float; "1" is not a version.
                'protocol_version' => self::majorMinor($interface['protocol_version'] ?? '1.0'),
            ];
        }

        return $result;
    }

    private static function majorMinor(mixed $version): string
    {
        return \is_float($version) ? number_format($version, 1, '.', '') : (string) $version;
    }
}
