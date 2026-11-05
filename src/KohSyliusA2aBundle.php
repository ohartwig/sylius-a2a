<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a;

use Koh\SyliusA2a\Controller\JsonRpcController;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * An A2A 1.0 agent for the shop: the JSON-RPC endpoint, its skills and the
 * Agent Card that names them.
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
                ->arrayNode('endpoint')
                    ->info('The A2A JSON-RPC endpoint this package serves at '.JsonRpcController::PATH.'. Disabled: the path answers 404 and the card names only the configured "interfaces".')
                    ->canBeDisabled()
                    ->children()
                        ->integerNode('rate_limit')
                            ->defaultValue(60)
                            ->min(1)
                            ->info('Requests per client address and rate_interval.')
                        ->end()
                        ->scalarNode('rate_interval')
                            ->defaultValue('1 minute')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('max_request_bytes')
                            ->defaultValue(65536)
                            ->min(1024)
                            ->info('Larger request bodies are refused with HTTP 413 before they are parsed.')
                        ->end()
                        ->integerNode('task_ttl')
                            ->defaultValue(86400)
                            ->min(60)
                            ->info('Seconds a task id stays retrievable with GetTask.')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('age_restriction')
                    ->defaultNull()
                    ->min(0)
                    ->info('Minimum age carried on every product the skills return (as the ACP feed does). Null: not carried.')
                ->end()
                ->arrayNode('interfaces')
                    ->info('Further A2A interfaces serving this same agent, listed after the endpoint. With the endpoint disabled and none here, the card answers 404 and is not announced.')
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

        $endpoint = \is_array($config['endpoint'] ?? null) ? $config['endpoint'] : [];
        $endpointEnabled = (bool) ($endpoint['enabled'] ?? true);
        $builder->setParameter('koh_a2a.endpoint.enabled', $endpointEnabled);
        $builder->setParameter('koh_a2a.endpoint.rate_limit', self::int($endpoint['rate_limit'] ?? null, 60));
        $builder->setParameter('koh_a2a.endpoint.rate_interval', \is_string($endpoint['rate_interval'] ?? null) ? $endpoint['rate_interval'] : '1 minute');
        $builder->setParameter('koh_a2a.endpoint.max_request_bytes', self::int($endpoint['max_request_bytes'] ?? null, 65536));
        $builder->setParameter('koh_a2a.endpoint.task_ttl', self::int($endpoint['task_ttl'] ?? null, 86400));
        $ageRestriction = $config['age_restriction'] ?? null;
        $builder->setParameter('koh_a2a.age_restriction', \is_int($ageRestriction) ? $ageRestriction : null);
        $builder->setParameter('koh_a2a.card_served', $endpointEnabled || [] !== $interfaces);

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

    private static function int(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }

    private static function majorMinor(mixed $version): string
    {
        return \is_float($version) ? number_format($version, 1, '.', '') : (string) $version;
    }
}
