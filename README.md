<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-a2a

An [A2A](https://a2a-protocol.org/) agent for a Sylius shop: a JSON-RPC
endpoint at `/api/agent/a2a` speaking A2A 1.0 (spec release v1.0.1), two
catalogue skills behind it, and the Agent Card at
`/.well-known/agent-card.json` that names both. No language model and no API
key: every skill is a deterministic, read-only catalogue lookup.

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

The endpoint sits under `/api/agent/`, the agent API prefix. Keep that prefix
out of the session-bearing shop firewall, as for the other agent documents —
otherwise every agent call starts a shop session:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        koh_agent_public:
            pattern: '^/(\.well-known/(?!oauth)|api/agent/)'
            security: false
```

## Configure

Everything has a default; a shop that configures nothing serves the endpoint
and the card.

```yaml
# config/packages/koh_a2a.yaml
koh_a2a:
    version: '1.0.0'                  # the agent's own version, on the card
    description: 'Tobacco, shipped from Germany.'   # defaults to a sentence with the channel name
    age_restriction: 18               # carried on every product returned; default: not carried
    endpoint:
        enabled: true                 # false: /api/agent/a2a answers 404
        rate_limit: 60                # requests per client address ...
        rate_interval: '1 minute'     # ... and interval (sliding window)
        max_request_bytes: 65536      # larger bodies: HTTP 413, before parsing
        task_ttl: 86400               # seconds a task id stays retrievable
    interfaces: []                    # further interfaces serving this same agent
```

## The endpoint

JSON-RPC 2.0 over `POST /api/agent/a2a`, `Content-Type: application/json`, one
call per request (batches are refused). Clients send `A2A-Version: 1.0` (a
header, or the `A2A-Version` query parameter); the patch level is ignored. A
request without one is a 0.3 client by the spec's rule and gets
`VersionNotSupportedError` (-32009).

| Method | Answer |
|---|---|
| `SendMessage` | A `Task` in `TASK_STATE_COMPLETED` with the result as an artifact |
| `GetTask` | The same task, rebuilt from its id |
| `CancelTask` | `TaskNotCancelableError` (-32002): every task is finished when it is created |
| `ListTasks` | An empty list: tasks belong to no account, so none is visible to anyone |
| `SendStreamingMessage`, `SubscribeToTask`, `GetExtendedAgentCard` | `UnsupportedOperationError` (-32004) |
| push-notification configuration | `PushNotificationNotSupportedError` (-32003) |

Errors carry `google.rpc.ErrorInfo` / `google.rpc.BadRequest` details in
`error.data`. Protocol errors come back with HTTP 200; only an oversized body
(413) and a client over its rate limit (429 with `Retry-After`) keep an HTTP
status.

### Skills

- **`product-search`** — products whose name contains the term, as the
  storefront search finds them: enabled, assigned to the channel, translated
  into the locale. Send the term as a text part, or a data part
  `{"query": "...", "limit": 10, "offset": 0, "locale": "en_GB"}` (limit up to
  25, paging within the first 200 results). When a whole sentence matches no
  name, its words are tried one by one and the result says which matched.
- **`product-detail`** — one product by code or slug: a data part
  `{"skill": "product-detail", "code": "..."}` (or `"slug"`).

Each product comes with its price (the channel price, gross in a shop that
prices with tax included, in minor units, currency and formatted for the
locale), the struck-through original price where there is one, availability as
the storefront's inventory checker reports it, and the product page URL. The
artifact holds an `application/json` data part and a `text/plain` rendering;
`configuration.acceptedOutputModes` narrows that. A message picks its skill
with a `skill` field (data part or message metadata); without one, a `code` or
`slug` means product detail and anything else a search.

### Tasks without storage

A task id is the task: skill, arguments, channel, locale, context and creation
time, signed with the kernel secret. `GetTask` checks the signature and runs
the lookup again, so any replica answers and nothing is written; a forged,
tampered or expired id is not found. The artifacts therefore show the
catalogue as it is when the task is fetched. No conversation state is kept: a
message to an existing task is refused (the task is finished), and history is
not returned.

## Discovery

The card lists `supportedInterfaces` with this endpoint first, then any
configured `interfaces`, and the skills the endpoint runs — not the shared
skill registry, which also holds skills other interfaces serve. While the card
is served, the endpoint is announced to the discovery registry (`llms.txt`,
the readiness check) and the JSON-RPC endpoint to the RFC 9727 api-catalog
with the card as its `service-desc`, which also puts it into the Link headers.
With the endpoint disabled and no `interfaces`, the card answers 404 and
nothing announces it.

Do not list the MCP endpoint under `interfaces`. It speaks MCP, not A2A, and
needs an admin-delegated token. The binding is limited to the three A2A core
bindings, so `MCP` is rejected at container build. `transport_path` is
deprecated and ignored.

## Security

Public, unauthenticated, read-only. Nothing reaches an agent that an anonymous
storefront visitor could not see. Requests are rate-limited per client address
(Symfony RateLimiter over `cache.app`), the body is size-capped before parsing
and depth-capped while parsing, and messages are limited to 20 parts and 2000
characters of text. The services keep no per-request state, which matters
under FrankenPHP's worker mode.

## Decommission

Remove the `composer require`, the bundle entry, the route import and
`config/packages/koh_a2a.yaml`. No table and no cache pool; the rate limiter's
entries in `cache.app` expire on their own.

## Reporting vulnerabilities

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
