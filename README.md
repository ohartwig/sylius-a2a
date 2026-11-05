<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-a2a

Publishes an [A2A](https://a2a-protocol.org/) 1.0 Agent Card at
`/.well-known/agent-card.json` — once the shop has an A2A endpoint to name in
it. Until then the path answers 404 and the card is not announced anywhere.

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
    interfaces:                                     # empty by default: no card
        - url: '/a2a/v1'                            # or an absolute URL
          protocol_binding: JSONRPC                 # JSONRPC | GRPC | HTTP+JSON
          protocol_version: '1.0'                   # Major.Minor the endpoint speaks
```

## No interface, no card

A2A 1.0 makes `supportedInterfaces` a required field: the card exists to say
where an agent sends `SendMessage`. This package serves no A2A endpoint
itself, so it publishes the card only when `interfaces` names one that some
other component serves. With `interfaces` empty — the default —
`/.well-known/agent-card.json` answers 404, and the endpoint is left out of
the discovery registry, so `llms.txt`, the Link headers and the readiness check
do not point at it either.

Do not list the MCP endpoint here. It speaks MCP, not A2A, and needs an
admin-delegated token; earlier versions pointed the card at `/_mcp` and steered
agents into asking for admin rights to read public data. The binding is limited
to the three A2A core bindings, so `MCP` is rejected at container build.

`transport_path` is deprecated and ignored. Remove it from your configuration.

The card is A2A 1.0: no top-level `url` or `preferredTransport`, no
`capabilities.stateTransitionHistory`. A skill registered without tags is
tagged with its id, because `tags` is required.

## Skills come from the registry

Not from a list in this package. Two other documents project the same skills —
the agent-skills index and the MCP server card — and hand-maintaining three
copies is how they end up disagreeing about what the shop can do. Register a
skill once by implementing `AgentSkillProviderInterface` from
`koh/sylius-agent-contracts` and all three follow.

A shop with an interface but no skills still serves the card, with an empty
`skills` list.

## Decommission

Remove the `composer require`, the bundle entry, the route import and
`config/packages/koh_a2a.yaml`. No table, no cache pool.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
