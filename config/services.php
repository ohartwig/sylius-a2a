<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusA2a\Agent\A2aEndpointProvider;
use Koh\SyliusA2a\Catalog\DoctrineProductSource;
use Koh\SyliusA2a\Catalog\ProductPresenter;
use Koh\SyliusA2a\Catalog\ProductSource;
use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusA2a\Controller\JsonRpcController;
use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Koh\SyliusA2a\Skill\ProductDetailSkill;
use Koh\SyliusA2a\Skill\ProductSearchSkill;
use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(AgentCardController::class)
        ->args([
            service(A2aMethods::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
            param('koh_a2a.version'),
            param('koh_a2a.description'),
            param('koh_a2a.interfaces'),
            param('koh_a2a.endpoint.enabled'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    // The endpoint's rate limiter, keyed by the client address the
    // installation's trusted_proxies yields — the same arrangement as the UCP
    // checkout limiter.
    $services->set('koh_a2a.rate_limiter.storage', CacheStorage::class)
        ->args([service('cache.app')]);
    $services->set('koh_a2a.rate_limiter', RateLimiterFactory::class)
        ->args([
            [
                'id' => 'koh_a2a_jsonrpc',
                'policy' => 'sliding_window',
                'limit' => param('koh_a2a.endpoint.rate_limit'),
                'interval' => param('koh_a2a.endpoint.rate_interval'),
            ],
            service('koh_a2a.rate_limiter.storage'),
        ]);

    $services->set(JsonRpcController::class)
        ->args([
            service(A2aMethods::class),
            service('koh_a2a.rate_limiter'),
            param('koh_a2a.endpoint.enabled'),
            param('koh_a2a.endpoint.max_request_bytes'),
            service('logger')->nullOnInvalid(),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(TaskIdCodec::class)
        ->args([param('kernel.secret'), param('koh_a2a.endpoint.task_ttl')]);

    $services->set(A2aMethods::class)
        ->args([
            tagged_iterator('koh_a2a.skill'),
            service(AgentContextFactoryInterface::class),
            service(TaskIdCodec::class),
        ]);

    $services->set(DoctrineProductSource::class)
        ->args([service('sylius.repository.product')]);
    $services->alias(ProductSource::class, DoctrineProductSource::class);

    $services->set(ProductPresenter::class)
        ->args([
            service('sylius.checker.inventory.availability'),
            service('sylius.formatter.money'),
            param('koh_a2a.age_restriction'),
        ]);

    $services->set(ProductSearchSkill::class)
        ->args([service(ProductSource::class), service(ProductPresenter::class)])
        ->tag('koh_a2a.skill', ['priority' => 20]);
    $services->set(ProductDetailSkill::class)
        ->args([service(ProductSource::class), service(ProductPresenter::class)])
        ->tag('koh_a2a.skill', ['priority' => 10]);

    $services->set(A2aEndpointProvider::class)
        ->args([param('koh_a2a.card_served'), param('koh_a2a.endpoint.enabled')])
        ->tag('koh_agent.endpoint')
        ->tag('koh_agent.api_catalog_entry');
};
