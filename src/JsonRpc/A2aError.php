<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\JsonRpc;

/**
 * A JSON-RPC error object as A2A 1.0 §9.5 shapes it.
 *
 * The numeric codes are the standard JSON-RPC ones and the A2A-specific
 * mapping of §5.4. `data` is a list of ProtoJSON `Any` objects, each with an
 * `@type`: `google.rpc.ErrorInfo` for A2A errors, `google.rpc.BadRequest` for
 * parameters that failed validation.
 */
final class A2aError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public const TASK_NOT_FOUND = -32001;
    public const TASK_NOT_CANCELABLE = -32002;
    public const PUSH_NOTIFICATION_NOT_SUPPORTED = -32003;
    public const UNSUPPORTED_OPERATION = -32004;
    public const CONTENT_TYPE_NOT_SUPPORTED = -32005;
    public const VERSION_NOT_SUPPORTED = -32009;

    private const DOMAIN = 'a2a-protocol.org';

    /**
     * @param list<array<string, mixed>> $details
     */
    private function __construct(int $code, string $message, private readonly array $details = [])
    {
        parent::__construct($message, $code);
    }

    public static function parseError(): self
    {
        return new self(self::PARSE_ERROR, 'Invalid JSON payload');
    }

    public static function invalidRequest(string $reason): self
    {
        return new self(self::INVALID_REQUEST, 'Request payload validation error', [
            self::errorInfo('INVALID_REQUEST', ['reason' => $reason]),
        ]);
    }

    public static function requestTooLarge(int $maxBytes): self
    {
        return new self(self::INVALID_REQUEST, 'Request payload validation error', [
            self::errorInfo('REQUEST_TOO_LARGE', ['maxBytes' => (string) $maxBytes]),
        ]);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, 'Method not found', [
            self::errorInfo('METHOD_NOT_FOUND', ['method' => $method]),
        ]);
    }

    public static function invalidParams(string $field, string $description): self
    {
        return new self(self::INVALID_PARAMS, 'Invalid parameters', [[
            '@type' => 'type.googleapis.com/google.rpc.BadRequest',
            'fieldViolations' => [['field' => $field, 'description' => $description]],
        ]]);
    }

    /** The cause is logged, not returned: a stack trace is no answer for an anonymous caller. */
    public static function internal(): self
    {
        return new self(self::INTERNAL_ERROR, 'Internal error');
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::INTERNAL_ERROR, 'Rate limit exceeded', [
            self::errorInfo('RATE_LIMIT_EXCEEDED', ['retryAfterSeconds' => (string) $retryAfter]),
        ]);
    }

    public static function taskNotFound(string $taskId): self
    {
        return new self(self::TASK_NOT_FOUND, 'Task not found', [
            self::errorInfo('TASK_NOT_FOUND', ['taskId' => self::clip($taskId)]),
        ]);
    }

    public static function taskNotCancelable(string $taskId, string $state): self
    {
        return new self(self::TASK_NOT_CANCELABLE, 'Task cannot be canceled', [
            self::errorInfo('TASK_NOT_CANCELABLE', ['taskId' => self::clip($taskId), 'state' => $state]),
        ]);
    }

    public static function pushNotificationNotSupported(): self
    {
        return new self(self::PUSH_NOTIFICATION_NOT_SUPPORTED, 'Push notifications are not supported', [
            self::errorInfo('PUSH_NOTIFICATION_NOT_SUPPORTED'),
        ]);
    }

    public static function unsupportedOperation(string $reason): self
    {
        return new self(self::UNSUPPORTED_OPERATION, 'Unsupported operation', [
            self::errorInfo('UNSUPPORTED_OPERATION', ['reason' => $reason]),
        ]);
    }

    /** @param list<string> $supported */
    public static function contentTypeNotSupported(string $mediaType, array $supported): self
    {
        return new self(self::CONTENT_TYPE_NOT_SUPPORTED, 'Content type not supported', [
            self::errorInfo('CONTENT_TYPE_NOT_SUPPORTED', [
                'mediaType' => self::clip($mediaType),
                'supportedMediaTypes' => implode(',', $supported),
            ]),
        ]);
    }

    /** @param list<string> $supported */
    public static function versionNotSupported(string $requested, array $supported): self
    {
        return new self(self::VERSION_NOT_SUPPORTED, 'Protocol version not supported', [
            self::errorInfo('VERSION_NOT_SUPPORTED', [
                'requestedVersion' => self::clip($requested),
                'supportedVersions' => implode(',', $supported),
            ]),
        ]);
    }

    /** @return array{code: int, message: string, data?: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ([] !== $this->details) {
            $error['data'] = $this->details;
        }

        return $error;
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, mixed>
     */
    private static function errorInfo(string $reason, array $metadata = []): array
    {
        $info = [
            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => $reason,
            'domain' => self::DOMAIN,
        ];
        if ([] !== $metadata) {
            $info['metadata'] = $metadata;
        }

        return $info;
    }

    /** Values echoed from the request are cut: an error is not a place to reflect a megabyte back. */
    private static function clip(string $value): string
    {
        return mb_substr($value, 0, 200);
    }
}
