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
```

## No transport, on purpose

The card names no `supportedInterfaces`: this package serves no A2A endpoint,
and a card that points agents at something that is not A2A is worse than one
that names nothing. Earlier versions pointed it at the MCP endpoint `/_mcp`,
which speaks MCP and needs an admin-delegated token, so an agent following the
card was steered into asking for admin rights to read public data.

`transport_path` is deprecated and ignored. Remove it from your configuration.
An interface comes back once the shop serves A2A itself.

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
