<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\JsonRpc;

use Koh\SyliusA2a\Skill\A2aSkillInterface;
use Koh\SyliusA2a\Skill\SkillInput;
use Koh\SyliusA2a\Skill\SkillResult;
use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusA2a\Task\TaskReference;
use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;

/**
 * The A2A 1.0 operations behind the JSON-RPC binding.
 *
 * Every message is answered with a task that is already finished: the skills
 * are catalogue lookups that complete within the request, so SendMessage
 * returns TASK_STATE_COMPLETED with the result as an artifact — blocking or
 * not, the answer is the same (§3.2.2). GetTask rebuilds a task from its id
 * (see TaskIdCodec), CancelTask finds every task already terminal and answers
 * TaskNotCancelableError, and ListTasks lists nothing: tasks belong to no
 * account on a public endpoint, so none is visible to any caller (§3.1.4).
 *
 * The optional capabilities are not declared on the card and answer the error
 * §3.3.4 prescribes: streaming and resubscription UnsupportedOperationError,
 * push-notification configuration PushNotificationNotSupportedError, the
 * extended card UnsupportedOperationError.
 *
 * No state outside the call: what a request needs travels in its arguments.
 */
final class A2aMethods
{
    public const PROTOCOL_VERSION = '1.0';

    /** Media types the skills produce, in order of preference. */
    public const OUTPUT_MODES = ['application/json', 'text/plain'];

    /** Media types a message part may carry. */
    public const INPUT_MODES = ['text/plain', 'application/json'];

    private const MAX_PARTS = 20;
    private const MAX_TEXT_LENGTH = 2000;
    private const MAX_ID_LENGTH = 128;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 100;

    private const TASK_STATES = [
        'TASK_STATE_UNSPECIFIED', 'TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING', 'TASK_STATE_COMPLETED',
        'TASK_STATE_FAILED', 'TASK_STATE_CANCELED', 'TASK_STATE_INPUT_REQUIRED', 'TASK_STATE_REJECTED',
        'TASK_STATE_AUTH_REQUIRED',
    ];

    /** @var array<string, A2aSkillInterface> */
    private readonly array $skills;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param iterable<A2aSkillInterface> $skills
     * @param (\Closure(): int)|null      $clock  Unix time; injectable for tests
     */
    public function __construct(
        iterable $skills,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly TaskIdCodec $taskIds,
        ?\Closure $clock = null,
    ) {
        $byId = [];
        foreach ($skills as $skill) {
            $byId[$skill->id()] = $skill;
        }
        $this->skills = $byId;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return list<A2aSkillInterface> */
    public function skills(): array
    {
        return array_values($this->skills);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws A2aError
     */
    public function call(string $method, array $params): array
    {
        return match ($method) {
            'SendMessage' => $this->sendMessage($params),
            'GetTask' => $this->getTask($params),
            'CancelTask' => $this->cancelTask($params),
            'ListTasks' => $this->listTasks($params),
            'SendStreamingMessage', 'SubscribeToTask' => throw A2aError::unsupportedOperation('streaming is not supported (capabilities.streaming is false)'),
            'CreateTaskPushNotificationConfig', 'GetTaskPushNotificationConfig',
            'ListTaskPushNotificationConfigs', 'DeleteTaskPushNotificationConfig' => throw A2aError::pushNotificationNotSupported(),
            'GetExtendedAgentCard' => throw A2aError::unsupportedOperation('there is no extended agent card (capabilities.extendedAgentCard is false)'),
            default => throw A2aError::methodNotFound($method),
        };
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{task: array<string, mixed>}
     */
    private function sendMessage(array $params): array
    {
        $message = $params['message'] ?? null;
        if (!\is_array($message) || array_is_list($message)) {
            throw A2aError::invalidParams('message', 'message is required and must be an object');
        }

        $configuration = $params['configuration'] ?? [];
        if (!\is_array($configuration)) {
            throw A2aError::invalidParams('configuration', 'configuration must be an object');
        }
        if (isset($configuration['taskPushNotificationConfig'])) {
            throw A2aError::pushNotificationNotSupported();
        }
        $outputModes = $this->outputModes($configuration['acceptedOutputModes'] ?? null);
        // Validated, and otherwise moot: no message is kept, so every task is
        // returned without history (§3.2.4 leaves the default to the agent).
        $this->historyLength($configuration['historyLength'] ?? null, 'configuration.historyLength');

        $this->requireString($message, 'messageId', 'message.messageId');
        if ('ROLE_USER' !== ($message['role'] ?? null)) {
            throw A2aError::invalidParams('message.role', 'role must be ROLE_USER');
        }
        $input = $this->input($message['parts'] ?? null);

        // A message addressed to an existing task continues it. Every task here
        // is finished the moment it is created, and a terminal task takes no
        // further messages (§3.1.1).
        if (isset($message['taskId'])) {
            if (!\is_string($message['taskId'])) {
                throw A2aError::invalidParams('message.taskId', 'taskId must be a string');
            }
            $this->reference($message['taskId']);
            throw A2aError::unsupportedOperation('the task is already completed and takes no further messages; send a new message without taskId');
        }

        $contextId = $this->contextId($message['contextId'] ?? null);
        $context = $this->contextFactory->create();
        $localeCode = $this->locale($input, $message['metadata'] ?? null, $context);
        $skill = $this->skill($input, $message['metadata'] ?? null);

        $reference = new TaskReference(
            skillId: $skill->id(),
            arguments: $skill->arguments($input),
            channelCode: (string) $context->channel->getCode(),
            localeCode: $localeCode,
            contextId: $contextId,
            createdAt: ($this->clock)(),
            outputModes: $outputModes,
            nonce: bin2hex(random_bytes(8)),
        );

        return ['task' => $this->task($reference, $skill, $context)];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function getTask(array $params): array
    {
        $id = $this->requireString($params, 'id', 'id');
        $this->historyLength($params['historyLength'] ?? null, 'historyLength');
        $reference = $this->reference($id);

        return $this->task($reference, $this->skills[$reference->skillId], $this->contextFactory->create(), $id);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function cancelTask(array $params): array
    {
        $id = $this->requireString($params, 'id', 'id');
        $this->reference($id);

        throw A2aError::taskNotCancelable($id, 'TASK_STATE_COMPLETED');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function listTasks(array $params): array
    {
        $pageSize = $params['pageSize'] ?? self::DEFAULT_PAGE_SIZE;
        if (!\is_int($pageSize) || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw A2aError::invalidParams('pageSize', \sprintf('pageSize must be an integer from 1 to %d', self::MAX_PAGE_SIZE));
        }
        if (isset($params['status']) && !\in_array($params['status'], self::TASK_STATES, true)) {
            throw A2aError::invalidParams('status', 'status must be a TaskState name such as TASK_STATE_COMPLETED');
        }
        if (isset($params['pageToken']) && '' !== $params['pageToken']) {
            // No page was ever handed out, so no token can be one of ours.
            throw A2aError::invalidParams('pageToken', 'pageToken is not a token this agent issued');
        }
        $this->historyLength($params['historyLength'] ?? null, 'historyLength');

        return ['tasks' => [], 'nextPageToken' => '', 'pageSize' => $pageSize, 'totalSize' => 0];
    }

    /**
     * The task, run (again) from its reference.
     *
     * @return array<string, mixed>
     */
    private function task(TaskReference $reference, A2aSkillInterface $skill, AgentContext $context, ?string $id = null): array
    {
        $id ??= $this->taskIds->encode($reference);
        $result = $skill->run($reference->arguments, $context, $reference->localeCode);

        $task = [
            'id' => $id,
            'contextId' => $reference->contextId,
            'status' => [
                'state' => 'TASK_STATE_COMPLETED',
                'message' => [
                    // Derived from the task, so GetTask returns the same message.
                    'messageId' => 'status-'.hash('xxh128', $id),
                    'contextId' => $reference->contextId,
                    'taskId' => $id,
                    'role' => 'ROLE_AGENT',
                    'parts' => [['text' => $result->summary, 'mediaType' => 'text/plain']],
                ],
                'timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', $reference->createdAt),
            ],
        ];

        $artifact = $this->artifact($result, $reference);
        if (null !== $artifact) {
            $task['artifacts'] = [$artifact];
        }
        $task['metadata'] = ['skill' => $skill->id(), 'locale' => $reference->localeCode];

        return $task;
    }

    /** @return array<string, mixed>|null */
    private function artifact(SkillResult $result, TaskReference $reference): ?array
    {
        if (null === $result->data) {
            return null;
        }

        $parts = [];
        foreach ($reference->outputModes as $mode) {
            $parts[] = 'application/json' === $mode
                ? ['data' => $result->data, 'mediaType' => 'application/json']
                : ['text' => $result->text, 'mediaType' => 'text/plain'];
        }

        return [
            'artifactId' => $result->artifactName,
            'name' => $result->artifactName,
            'parts' => $parts,
        ];
    }

    /** The reference a task id stands for, or TaskNotFoundError. */
    private function reference(string $taskId): TaskReference
    {
        $reference = $this->taskIds->decode($taskId, ($this->clock)());
        $context = $this->contextFactory->create();
        if (null === $reference
            || !isset($this->skills[$reference->skillId])
            || $reference->channelCode !== (string) $context->channel->getCode()
        ) {
            throw A2aError::taskNotFound($taskId);
        }

        return $reference;
    }

    /**
     * The text and data parts of a message. Other content — files by bytes or
     * by URL — is nothing a catalogue lookup can read (§3.1.1
     * ContentTypeNotSupportedError).
     */
    private function input(mixed $parts): SkillInput
    {
        if (!\is_array($parts) || !array_is_list($parts) || [] === $parts) {
            throw A2aError::invalidParams('message.parts', 'at least one part is required');
        }
        if (\count($parts) > self::MAX_PARTS) {
            throw A2aError::invalidParams('message.parts', \sprintf('at most %d parts are accepted', self::MAX_PARTS));
        }

        $texts = [];
        $data = [];
        foreach ($parts as $index => $part) {
            $field = 'message.parts['.$index.']';
            if (!\is_array($part) || array_is_list($part) && [] !== $part) {
                throw A2aError::invalidParams($field, 'a part must be an object');
            }
            $content = array_values(array_intersect(['text', 'raw', 'url', 'data'], array_keys($part)));
            if (1 !== \count($content)) {
                throw A2aError::invalidParams($field, 'a part carries exactly one of text, raw, url or data');
            }
            $mediaType = $part['mediaType'] ?? null;
            if (null !== $mediaType && !\is_string($mediaType)) {
                throw A2aError::invalidParams($field.'.mediaType', 'mediaType must be a string');
            }

            switch ($content[0]) {
                case 'text':
                    if (!\is_string($part['text'])) {
                        throw A2aError::invalidParams($field.'.text', 'text must be a string');
                    }
                    if (null !== $mediaType && '' !== $mediaType && !str_starts_with(strtolower($mediaType), 'text/plain')) {
                        throw A2aError::contentTypeNotSupported($mediaType, self::INPUT_MODES);
                    }
                    $texts[] = $part['text'];
                    break;
                case 'data':
                    if (null !== $mediaType && '' !== $mediaType && 'application/json' !== strtolower($mediaType)) {
                        throw A2aError::contentTypeNotSupported($mediaType, self::INPUT_MODES);
                    }
                    if (\is_array($part['data']) && !array_is_list($part['data'])) {
                        // The first data part's fields win over a later one's.
                        $data += $part['data'];
                    } elseif (\is_string($part['data'])) {
                        $texts[] = $part['data'];
                    } else {
                        throw A2aError::invalidParams($field.'.data', 'data must be an object');
                    }
                    break;
                default:
                    throw A2aError::contentTypeNotSupported(\is_string($mediaType) && '' !== $mediaType ? $mediaType : 'file ('.$content[0].')', self::INPUT_MODES);
            }
        }

        $text = trim(implode(' ', $texts));
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw A2aError::invalidParams('message.parts', \sprintf('the text of a message is limited to %d characters', self::MAX_TEXT_LENGTH));
        }

        /** @var array<string, mixed> $data */
        return new SkillInput($text, $data);
    }

    private function skill(SkillInput $input, mixed $metadata): A2aSkillInterface
    {
        $requested = $input->data['skill'] ?? (\is_array($metadata) ? $metadata['skill'] ?? null : null);
        if (null === $requested) {
            // A code or slug names one product; anything else is a search.
            $requested = isset($input->data['code']) || isset($input->data['slug']) ? 'product-detail' : 'product-search';
        }
        if (!\is_string($requested) || !isset($this->skills[$requested])) {
            throw A2aError::invalidParams('message.parts.data.skill', 'skill must be one of: '.implode(', ', array_keys($this->skills)));
        }

        return $this->skills[$requested];
    }

    private function locale(SkillInput $input, mixed $metadata, AgentContext $context): string
    {
        $requested = $input->data['locale'] ?? (\is_array($metadata) ? $metadata['locale'] ?? null : null);
        if (null === $requested) {
            return $context->localeCode;
        }
        if (!\is_string($requested) || !\in_array($requested, $context->localeCodes, true)) {
            throw A2aError::invalidParams('message.parts.data.locale', 'locale must be one of the shop\'s locales: '.implode(', ', $context->localeCodes));
        }

        return $requested;
    }

    private function contextId(mixed $contextId): string
    {
        if (null === $contextId) {
            return self::uuid();
        }
        // Accepted and kept: a context groups the caller's tasks and costs
        // this agent nothing, since no conversation state hangs off it.
        if (!\is_string($contextId) || 1 !== preg_match('/^[A-Za-z0-9._:-]{1,'.self::MAX_ID_LENGTH.'}$/', $contextId)) {
            throw A2aError::invalidParams('message.contextId', \sprintf('contextId must be 1 to %d characters of A-Z, a-z, 0-9, ".", "_", ":" or "-"', self::MAX_ID_LENGTH));
        }

        return $contextId;
    }

    /** @return list<string> */
    private function outputModes(mixed $accepted): array
    {
        if (null === $accepted || [] === $accepted) {
            return self::OUTPUT_MODES;
        }
        if (!\is_array($accepted) || !array_is_list($accepted)) {
            throw A2aError::invalidParams('configuration.acceptedOutputModes', 'acceptedOutputModes must be a list of media types');
        }

        $modes = [];
        foreach ($accepted as $mode) {
            if (!\is_string($mode)) {
                throw A2aError::invalidParams('configuration.acceptedOutputModes', 'acceptedOutputModes must be a list of media types');
            }
            $mode = strtolower(trim(explode(';', $mode)[0]));
            foreach (self::OUTPUT_MODES as $offered) {
                if ($mode === $offered || '*/*' === $mode || $mode === explode('/', $offered)[0].'/*') {
                    $modes[$offered] = true;
                }
            }
        }
        if ([] === $modes) {
            throw A2aError::contentTypeNotSupported(implode(',', array_filter($accepted, 'is_string')), self::OUTPUT_MODES);
        }

        return array_values(array_filter(self::OUTPUT_MODES, static fn (string $mode): bool => isset($modes[$mode])));
    }

    private function historyLength(mixed $historyLength, string $field): void
    {
        if (null !== $historyLength && (!\is_int($historyLength) || $historyLength < 0)) {
            throw A2aError::invalidParams($field, 'historyLength must be a non-negative integer');
        }
    }

    /** @param array<string, mixed> $object */
    private function requireString(array $object, string $key, string $field): string
    {
        $value = $object[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw A2aError::invalidParams($field, $key.' is required and must be a non-empty string');
        }
        if (\strlen($value) > TaskIdCodec::MAX_LENGTH) {
            throw A2aError::invalidParams($field, $key.' is too long');
        }

        return $value;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
