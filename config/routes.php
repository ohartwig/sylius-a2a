<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusA2a\Controller\AgentCardController;
use Koh\SyliusA2a\Controller\JsonRpcController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_a2a_agent_card', '/.well-known/agent-card.json')
        ->controller(AgentCardController::class)
        ->methods(['GET', 'HEAD']);

    // Under /api/agent/, next to the ACP feed: the agent API prefix that
    // installations already keep out of the session-bearing shop firewall.
    $routes->add('koh_a2a_jsonrpc', JsonRpcController::PATH)
        ->controller(JsonRpcController::class)
        ->methods(['POST', 'OPTIONS']);
};
