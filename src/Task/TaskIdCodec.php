<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Task;

/**
 * Task ids that carry their own task.
 *
 * Every skill this agent runs is a deterministic, read-only catalogue lookup
 * that completes inside the request. Storing the finished task so that GetTask
 * can find it again would need a store shared by every replica — the default
 * cache is per pod — and would hand an anonymous caller a write path into it.
 * Instead the task id is the task's reference itself: skill, arguments,
 * channel, locale, context and creation time, signed with the kernel secret.
 * GetTask verifies the signature and runs the lookup again; any replica can
 * answer, nothing is written, and a forged or tampered id is simply not found.
 *
 * A reference expires after `$ttl` seconds, after which the task counts as
 * purged (A2A 1.0 §3.3.2 allows TaskNotFoundError for that).
 */
final class TaskIdCodec
{
    private const PREFIX = 't1.';

    /** Longer ids are not ours: the arguments are bounded, so a genuine id never gets near this. */
    public const MAX_LENGTH = 2048;

    public function __construct(
        private readonly string $secret,
        private readonly int $ttl = 86400,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('The task id secret must not be empty.');
        }
    }

    public function encode(TaskReference $reference): string
    {
        $payload = self::base64UrlEncode(json_encode([
            's' => $reference->skillId,
            'a' => $reference->arguments,
            'h' => $reference->channelCode,
            'l' => $reference->localeCode,
            'c' => $reference->contextId,
            't' => $reference->createdAt,
            'm' => $reference->outputModes,
            'n' => $reference->nonce,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return self::PREFIX.$payload.'.'.$this->sign($payload);
    }

    /** The reference, or null when the id is malformed, forged or expired. */
    public function decode(string $taskId, int $now): ?TaskReference
    {
        if (\strlen($taskId) > self::MAX_LENGTH || !str_starts_with($taskId, self::PREFIX)) {
            return null;
        }

        $parts = explode('.', substr($taskId, \strlen(self::PREFIX)));
        if (2 !== \count($parts) || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return null;
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        try {
            $data = json_decode((string) $json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)
            || !\is_string($data['s'] ?? null)
            || !\is_array($data['a'] ?? null)
            || !\is_string($data['h'] ?? null)
            || !\is_string($data['l'] ?? null)
            || !\is_string($data['c'] ?? null)
            || !\is_int($data['t'] ?? null)
            || !\is_array($data['m'] ?? null)
            || !\is_string($data['n'] ?? null)
        ) {
            return null;
        }

        $age = $now - $data['t'];
        if ($age < 0 || $age > $this->ttl) {
            return null;
        }

        /** @var array<string, mixed> $arguments */
        $arguments = $data['a'];

        return new TaskReference(
            skillId: $data['s'],
            arguments: $arguments,
            channelCode: $data['h'],
            localeCode: $data['l'],
            contextId: $data['c'],
            createdAt: $data['t'],
            outputModes: array_values(array_filter($data['m'], 'is_string')),
            nonce: $data['n'],
        );
    }

    private function sign(string $payload): string
    {
        // 128 bits of HMAC-SHA256 are plenty for an id that unlocks nothing
        // but a public catalogue lookup, and keep the id short.
        return self::base64UrlEncode(substr(hash_hmac('sha256', self::PREFIX.$payload, $this->secret, true), 0, 16));
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
