<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusA2a\Agent\A2aEndpointProvider;
use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\AgentSkillRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(AgentCardController::class)
        ->args([
            service(AgentSkillRegistry::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
            param('koh_a2a.version'),
            param('koh_a2a.description'),
            param('koh_a2a.transport_path'),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(A2aEndpointProvider::class)->tag('koh_agent.endpoint');
};
