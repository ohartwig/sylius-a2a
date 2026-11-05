<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Controller;

use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The A2A 1.0 JSON-RPC binding (§9): one POST endpoint, JSON-RPC 2.0 requests,
 * application/json responses.
 *
 * Public, unauthenticated and read-only. The bounds are therefore all here:
 * requests are rate-limited per client address, the body is size-capped before
 * it is parsed, nesting is capped while it is parsed, and batches are refused
 * — one call per request keeps the rate limit meaningful.
 *
 * Protocol errors go back as JSON-RPC errors with HTTP 200, as JSON-RPC over
 * HTTP expects. Two refusals happen before there is a call to answer and keep
 * their HTTP status: 413 for an oversized body and 429 (with Retry-After) for
 * a client over its limit.
 *
 * Version negotiation follows §3.6.2: the `A2A-Version` header (or query
 * parameter) is compared by Major.Minor, and an empty one means 0.3 — which
 * this endpoint does not speak, so it answers VersionNotSupportedError.
 *
 * Stateless: nothing of one request is kept on this service, which matters in
 * a worker that serves many requests from one process.
 */
final class JsonRpcController
{
    public const PATH = '/api/agent/a2a';

    private const MAX_DEPTH = 32;

    public function __construct(
        private readonly A2aMethods $methods,
        private readonly ?RateLimiterFactory $limiter = null,
        private readonly bool $enabled = true,
        private readonly int $maxRequestBytes = 65536,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->enabled) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        if ('OPTIONS' === $request->getMethod()) {
            return $this->preflight();
        }

        if (null !== $this->limiter) {
            $limit = $this->limiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true)));
                $response = $this->error(null, A2aError::rateLimited($retryAfter), Response::HTTP_TOO_MANY_REQUESTS);
                $response->headers->set('Retry-After', (string) $retryAfter);

                return $response;
            }
        }

        $declared = $request->headers->get('Content-Length');
        if ((null !== $declared && (int) $declared > $this->maxRequestBytes)
            || \strlen($body = $request->getContent()) > $this->maxRequestBytes
        ) {
            return $this->error(null, A2aError::requestTooLarge($this->maxRequestBytes), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $call = json_decode($body, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $this->error(null, A2aError::parseError());
        }

        if (!\is_array($call) || [] === $call || array_is_list($call)) {
            return $this->error(null, A2aError::invalidRequest(\is_array($call) && [] !== $call ? 'batch requests are not supported' : 'the request must be a JSON-RPC 2.0 request object'));
        }

        $hasId = \array_key_exists('id', $call);
        $id = $call['id'] ?? null;
        if (!(null === $id || \is_string($id) || \is_int($id))) {
            return $this->error(null, A2aError::invalidRequest('id must be a string, a number or null'));
        }
        if ('2.0' !== ($call['jsonrpc'] ?? null)) {
            return $this->error($id, A2aError::invalidRequest('jsonrpc must be "2.0"'));
        }
        if (!\is_string($call['method'] ?? null) || '' === $call['method']) {
            return $this->error($id, A2aError::invalidRequest('method must be a non-empty string'));
        }
        $params = $call['params'] ?? [];
        if (!\is_array($params) || ([] !== $params && array_is_list($params))) {
            return $this->error($id, A2aError::invalidRequest('params must be an object'));
        }

        // A notification asks for no response (JSON-RPC 2.0 §4.1). Every method
        // here only reads, so a call nobody waits for has nothing to do.
        if (!$hasId) {
            return $this->cors(new Response('', Response::HTTP_NO_CONTENT));
        }

        $version = $this->version($request);
        if (A2aMethods::PROTOCOL_VERSION !== $version) {
            return $this->error($id, A2aError::versionNotSupported('' === $version ? '0.3 (no A2A-Version header)' : $version, [A2aMethods::PROTOCOL_VERSION]));
        }

        try {
            /** @var array<string, mixed> $params */
            $result = $this->methods->call($call['method'], $params);
        } catch (A2aError $error) {
            return $this->error($id, $error);
        } catch (\Throwable $exception) {
            $this->logger?->error('A2A call failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return $this->error($id, A2aError::internal());
        }

        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /** The requested version as Major.Minor, or '' when none was sent. */
    private function version(Request $request): string
    {
        $version = $request->headers->get('A2A-Version');
        if (null === $version || '' === trim($version)) {
            $query = $request->query->all()['A2A-Version'] ?? null;
            $version = \is_string($query) ? $query : '';
        }
        $version = trim($version);

        // Patch versions are not negotiated (§3.6): 1.0.1 is 1.0.
        return 1 === preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/', $version, $match)
            ? $match[1].'.'.$match[2]
            : $version;
    }

    /** @param int|string|null $id */
    private function error(mixed $id, A2aError $error, int $status = Response::HTTP_OK): Response
    {
        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()], $status);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = Response::HTTP_OK): Response
    {
        $response = new Response(
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
            $status,
            ['Content-Type' => 'application/json', 'X-Content-Type-Options' => 'nosniff'],
        );
        // Answers depend on the request body; no shared cache may keep them.
        $response->headers->set('Cache-Control', 'no-store');

        return $this->cors($response);
    }

    private function preflight(): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, A2A-Version, A2A-Extensions');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $this->cors($response);
    }

    /**
     * Readable from any origin, like the agent card: the endpoint takes no
     * credentials and sets no cookie, so there is nothing a foreign page could
     * borrow.
     */
    private function cors(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
