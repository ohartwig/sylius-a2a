<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-a2a

Publishes an [A2A](https://a2a-protocol.org/) Agent Card at
`/.well-known/agent-card.json`.

## Install

```bash
composer require koh/sylius-a2a
```

```php
// config/bundles.php
Koh\SyliusA2a\KohSyliusA2aBundle::class => ['all' => true],
```

```yaml
# config/routes/koh_agent.yaml
koh_a2a:
    resource: '@KohSyliusA2aBundle/config/routes.php'
```

## Configure

```yaml
# config/packages/koh_a2a.yaml
koh_a2a:
    version: '1.0.0'
    description: 'Tobacco, shipped from Germany.'   # defaults to the channel name
    transport_path: '/_mcp'
```

`transport_path` must name an endpoint the shop actually serves. A card
advertising a transport that 404s is worse than no card.

## Skills come from the registry

Not from a list in this package. Two other documents project the same skills —
the agent-skills index and the MCP server card — and hand-maintaining three
copies is how they end up disagreeing about what the shop can do. Register a
skill once by implementing `AgentSkillProviderInterface` from
`koh/sylius-agent-contracts` and all three follow.

A shop with no skills still serves the card. It is discovery, not a capability:
an agent asking what this origin can do deserves an answer, even if the answer
is "nothing yet".

## Decommission

Remove the `composer require`, the bundle entry, the route import and
`config/packages/koh_a2a.yaml`. No table, no cache pool.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
