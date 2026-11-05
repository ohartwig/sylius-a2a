<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\JsonRpc;

use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusA2a\Task\TaskReference;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The A2A 1.0 operations, against the real skills over a small catalogue.
 */
#[CoversClass(A2aMethods::class)]
#[CoversClass(A2aError::class)]
final class A2aMethodsTest extends TestCase
{
    public function testSendMessageAnswersWithACompletedTaskCarryingTheResultAsAnArtifact(): void
    {
        $task = $this->send(['parts' => [['text' => 'robusto']]])['task'];

        self::assertStringStartsWith('t1.', $task['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $task['contextId']);
        self::assertSame('TASK_STATE_COMPLETED', $task['status']['state']);
        self::assertSame('2027-01-15T08:00:00.000Z', $task['status']['timestamp']);
        self::assertSame('ROLE_AGENT', $task['status']['message']['role']);
        self::assertSame($task['id'], $task['status']['message']['taskId']);
        self::assertSame('status-'.hash('xxh128', $task['id']), $task['status']['message']['messageId']);
        self::assertSame($task['contextId'], $task['status']['message']['contextId']);
        self::assertSame([['text' => 'Found 1 product for "robusto".', 'mediaType' => 'text/plain']], $task['status']['message']['parts']);
        self::assertSame(['skill' => 'product-search', 'locale' => 'de_DE'], $task['metadata']);
        self::assertArrayNotHasKey('history', $task);

        self::assertCount(1, $task['artifacts']);
        $artifact = $task['artifacts'][0];
        self::assertSame('product-search-results', $artifact['artifactId']);
        self::assertSame('product-search-results', $artifact['name']);
        self::assertSame('application/json', $artifact['parts'][0]['mediaType']);
        self::assertSame('text/plain', $artifact['parts'][1]['mediaType']);
        self::assertSame('ROBUSTO-1', $artifact['parts'][0]['data']['products'][0]['code']);
        self::assertStringStartsWith('1. Robusto Especial — from ', $artifact['parts'][1]['text']);
    }

    public function testEveryNewTaskGetsItsOwnIdAndContext(): void
    {
        $first = $this->send(['parts' => [['text' => 'robusto']]])['task'];
        $second = $this->send(['parts' => [['text' => 'robusto']]])['task'];

        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame($first['contextId'], $second['contextId']);
    }

    public function testAClientContextIdIsKept(): void
    {
        $task = $this->send(['contextId' => 'ctx-42:a.b_c', 'parts' => [['text' => 'robusto']]])['task'];

        self::assertSame('ctx-42:a.b_c', $task['contextId']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badContextIds(): iterable
    {
        yield 'not a string' => [42];
        yield 'empty' => [''];
        yield 'spaces' => ['a b'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('badContextIds')]
    public function testAContextIdThisAgentCannotKeepIsRejectedNotReplaced(mixed $contextId): void
    {
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['contextId' => $contextId, 'parts' => [['text' => 'robusto']]]));
    }

    public function testAMaximalContextIdIsAccepted(): void
    {
        $contextId = str_repeat('a', 128);

        self::assertSame($contextId, $this->send(['contextId' => $contextId, 'parts' => [['text' => 'robusto']]])['task']['contextId']);
    }

    public function testADataPartSelectsTheSkillAndItsArguments(): void
    {
        $task = $this->send(['parts' => [['data' => ['skill' => 'product-detail', 'code' => 'ROBUSTO-1']]]])['task'];

        self::assertSame('product-detail', $task['metadata']['skill']);
        self::assertSame('Robusto Especial', $task['artifacts'][0]['parts'][0]['data']['product']['name']);
    }

    public function testAResultWithoutDataIsACompletedTaskWithoutArtifacts(): void
    {
        $task = $this->send(['parts' => [['data' => ['code' => 'NOPE']]]])['task'];

        self::assertSame('TASK_STATE_COMPLETED', $task['status']['state']);
        self::assertSame('This shop has no product with the code or slug "NOPE".', $task['status']['message']['parts'][0]['text']);
        self::assertArrayNotHasKey('artifacts', $task);
    }

    public function testACodeWithoutASkillMeansProductDetail(): void
    {
        self::assertSame('product-detail', $this->send(['parts' => [['data' => ['code' => 'ROBUSTO-1']]]])['task']['metadata']['skill']);
        self::assertSame('product-detail', $this->send(['parts' => [['data' => ['slug' => 'robusto-especial']]]])['task']['metadata']['skill']);
    }

    public function testMetadataCanSelectTheSkill(): void
    {
        $task = $this->send(['metadata' => ['skill' => 'product-detail'], 'parts' => [['text' => 'CORONA-2']]])['task'];

        self::assertSame('Corona Clasica', $task['artifacts'][0]['parts'][0]['data']['product']['name']);
    }

    public function testADataPartSkillWinsOverMetadata(): void
    {
        $task = $this->send([
            'metadata' => ['skill' => 'product-detail'],
            'parts' => [['data' => ['skill' => 'product-search', 'query' => 'corona']]],
        ])['task'];

        self::assertSame('product-search', $task['metadata']['skill']);
    }

    public function testAnUnknownSkillIsInvalidAndNamesTheKnownOnes(): void
    {
        $error = $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['parts' => [['data' => ['skill' => 'checkout', 'query' => 'x']]]]));

        self::assertStringContainsString('product-search, product-detail', $this->violation($error));
    }

    public function testANonStringSkillIsInvalid(): void
    {
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['metadata' => ['skill' => 7], 'parts' => [['text' => 'x']]]));
    }

    public function testTheLocaleCanBeChosenAmongTheChannelLocales(): void
    {
        $task = $this->send(['parts' => [['data' => ['query' => 'robusto', 'locale' => 'en_GB']]]])['task'];

        self::assertSame('en_GB', $task['metadata']['locale']);
        self::assertSame('Robusto Special', $task['artifacts'][0]['parts'][0]['data']['products'][0]['name']);
        self::assertSame('https://shop.example.com/en_GB/products/robusto-special', $task['artifacts'][0]['parts'][0]['data']['products'][0]['url']);
    }

    public function testTheLocaleCanComeFromMetadata(): void
    {
        self::assertSame('en_GB', $this->send(['metadata' => ['locale' => 'en_GB'], 'parts' => [['text' => 'robusto']]])['task']['metadata']['locale']);
    }

    public function testALocaleOutsideTheChannelIsRejected(): void
    {
        $error = $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['parts' => [['data' => ['query' => 'robusto', 'locale' => 'fr_FR']]]]));

        self::assertStringContainsString('de_DE, en_GB', $this->violation($error));
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['metadata' => ['locale' => 1], 'parts' => [['text' => 'robusto']]]));
    }

    public function testTextPartsAreJoined(): void
    {
        $task = $this->send(['parts' => [['text' => ' robusto '], ['text' => 'especial']]])['task'];

        self::assertSame('robusto especial', $task['artifacts'][0]['parts'][0]['data']['query']);
    }

    public function testAStringDataPartCountsAsText(): void
    {
        self::assertSame('corona', $this->send(['parts' => [['data' => 'corona']]])['task']['artifacts'][0]['parts'][0]['data']['query']);
    }

    public function testTheFirstDataPartWins(): void
    {
        $task = $this->send(['parts' => [['data' => ['query' => 'corona']], ['data' => ['query' => 'robusto', 'limit' => 2]]]])['task'];

        self::assertSame('corona', $task['artifacts'][0]['parts'][0]['data']['query']);
    }

    public function testAcceptedOutputModesNarrowTheArtifactParts(): void
    {
        $json = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['application/json']])['task'];
        $text = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['text/plain; charset=utf-8']])['task'];
        $wildcard = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['text/*']])['task'];
        $any = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['*/*']])['task'];
        $empty = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => []])['task'];

        self::assertSame(['application/json'], array_column($json['artifacts'][0]['parts'], 'mediaType'));
        self::assertSame(['text/plain'], array_column($text['artifacts'][0]['parts'], 'mediaType'));
        self::assertSame(['text/plain'], array_column($wildcard['artifacts'][0]['parts'], 'mediaType'));
        self::assertSame(['application/json', 'text/plain'], array_column($any['artifacts'][0]['parts'], 'mediaType'));
        self::assertSame(['application/json', 'text/plain'], array_column($empty['artifacts'][0]['parts'], 'mediaType'));
    }

    public function testTheOutputModesAreKeptInTheOrderThisAgentPrefers(): void
    {
        $task = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['text/plain', 'application/json']])['task'];

        self::assertSame(['application/json', 'text/plain'], array_column($task['artifacts'][0]['parts'], 'mediaType'));
    }

    public function testOutputModesNoneOfWhichThisAgentProducesAreAContentTypeError(): void
    {
        $error = $this->expectA2aError(A2aError::CONTENT_TYPE_NOT_SUPPORTED, fn () => $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['image/png']]));

        self::assertSame('image/png', $error['data'][0]['metadata']['mediaType']);
        self::assertSame('application/json,text/plain', $error['data'][0]['metadata']['supportedMediaTypes']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badOutputModes(): iterable
    {
        yield 'not a list' => [['a' => 'text/plain']];
        yield 'a string' => ['text/plain'];
        yield 'a number inside' => [['text/plain', 3]];
    }

    #[DataProvider('badOutputModes')]
    public function testMalformedOutputModesAreInvalid(mixed $modes): void
    {
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => $modes]));
    }

    public function testAPushNotificationConfigIsRefused(): void
    {
        $this->expectA2aError(A2aError::PUSH_NOTIFICATION_NOT_SUPPORTED, fn () => $this->send(['parts' => [['text' => 'robusto']]], ['taskPushNotificationConfig' => ['url' => 'https://example.com/hook']]));
    }

    public function testReturnImmediatelyStillAnswersWithTheFinishedTask(): void
    {
        $task = $this->send(['parts' => [['text' => 'robusto']]], ['returnImmediately' => true])['task'];

        self::assertSame('TASK_STATE_COMPLETED', $task['status']['state']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badHistoryLengths(): iterable
    {
        yield 'negative' => [-1];
        yield 'a string' => ['3'];
    }

    #[DataProvider('badHistoryLengths')]
    public function testAHistoryLengthMustBeANonNegativeInteger(mixed $historyLength): void
    {
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->send(['parts' => [['text' => 'robusto']]], ['historyLength' => $historyLength]));
        $task = $this->send(['parts' => [['text' => 'robusto']]])['task'];
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('GetTask', ['id' => $task['id'], 'historyLength' => $historyLength]));
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('ListTasks', ['historyLength' => $historyLength]));
    }

    public function testAZeroHistoryLengthIsFine(): void
    {
        self::assertArrayNotHasKey('history', $this->send(['parts' => [['text' => 'robusto']]], ['historyLength' => 0])['task']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidMessages(): iterable
    {
        $parts = [['text' => 'robusto']];
        yield 'no message id' => [['role' => 'ROLE_USER', 'parts' => $parts], 'message.messageId'];
        yield 'empty message id' => [['messageId' => '', 'role' => 'ROLE_USER', 'parts' => $parts], 'message.messageId'];
        yield 'numeric message id' => [['messageId' => 1, 'role' => 'ROLE_USER', 'parts' => $parts], 'message.messageId'];
        yield 'oversized message id' => [['messageId' => str_repeat('m', TaskIdCodec::MAX_LENGTH + 1), 'role' => 'ROLE_USER', 'parts' => $parts], 'message.messageId'];
        yield 'agent role' => [['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => $parts], 'message.role'];
        yield '0.3 role' => [['messageId' => 'm', 'role' => 'user', 'parts' => $parts], 'message.role'];
        yield 'no parts' => [['messageId' => 'm', 'role' => 'ROLE_USER'], 'message.parts'];
        yield 'empty parts' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => []], 'message.parts'];
        yield 'parts as object' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => ['a' => ['text' => 'x']]], 'message.parts'];
        yield 'too many parts' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => array_fill(0, 21, ['text' => 'x'])], 'message.parts'];
        yield 'part not an object' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => ['robusto']], 'message.parts[0]'];
        yield 'part is a list' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['robusto']]], 'message.parts[0]'];
        yield 'part without content' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [[]]], 'message.parts[0]'];
        yield 'part with two contents' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 'a', 'data' => ['q' => 1]]]], 'message.parts[0]'];
        yield 'text not a string' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 5]]], 'message.parts[0].text'];
        yield 'media type not a string' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 'a', 'mediaType' => 5]]], 'message.parts[0].mediaType'];
        yield 'data a number' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['data' => 5]]], 'message.parts[0].data'];
        yield 'data a list' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['data' => ['a', 'b']]]], 'message.parts[0].data'];
        yield 'text too long' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => str_repeat('a', 2001)]]], 'message.parts'];
        yield 'task id not a string' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => $parts, 'taskId' => 5], 'message.taskId'];
        yield 'blank text' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => '   ']]], 'message.parts'];
    }

    /** @param array<string, mixed> $message */
    #[DataProvider('invalidMessages')]
    public function testAnInvalidMessageIsRejectedWithTheFieldThatFailed(array $message, string $field): void
    {
        $error = $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('SendMessage', ['message' => $message]));

        self::assertSame('type.googleapis.com/google.rpc.BadRequest', $error['data'][0]['@type']);
        self::assertSame($field, $error['data'][0]['fieldViolations'][0]['field']);
    }

    public function testTwentyPartsAndTwoThousandCharactersAreStillAccepted(): void
    {
        $parts = array_fill(0, 19, ['text' => 'a']);
        $parts[] = ['text' => 'robusto'];
        self::assertSame('TASK_STATE_COMPLETED', $this->send(['parts' => $parts])['task']['status']['state']);

        // The message takes 2000 characters; the search cuts its term to 100.
        $task = $this->send(['parts' => [['text' => str_repeat('a', 2000)]]])['task'];
        self::assertSame(str_repeat('a', 100), $task['artifacts'][0]['parts'][0]['data']['query']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function missingMessages(): iterable
    {
        yield 'absent' => [null];
        yield 'a string' => ['hello'];
        yield 'a list' => [[['text' => 'x']]];
        yield 'empty' => [[]];
    }

    #[DataProvider('missingMessages')]
    public function testAMessageIsRequired(mixed $message): void
    {
        $params = null === $message ? [] : ['message' => $message];
        $error = $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('SendMessage', $params));

        self::assertSame('message', $error['data'][0]['fieldViolations'][0]['field']);
    }

    public function testAConfigurationMustBeAnObject(): void
    {
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('SendMessage', ['message' => $this->message(['parts' => [['text' => 'x']]]), 'configuration' => 'blocking']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unreadableParts(): iterable
    {
        yield 'raw bytes' => [['raw' => base64_encode('x'), 'mediaType' => 'image/png']];
        yield 'url' => [['url' => 'https://example.com/a.pdf']];
        yield 'text as html' => [['text' => '<b>x</b>', 'mediaType' => 'text/html']];
        yield 'data as xml' => [['data' => ['query' => 'x'], 'mediaType' => 'application/xml']];
    }

    /** @param array<string, mixed> $part */
    #[DataProvider('unreadableParts')]
    public function testPartsThisAgentCannotReadAreAContentTypeError(array $part): void
    {
        $this->expectA2aError(A2aError::CONTENT_TYPE_NOT_SUPPORTED, fn () => $this->send(['parts' => [$part]]));
    }

    public function testAFilePartWithoutMediaTypeNamesItsKind(): void
    {
        $error = $this->expectA2aError(A2aError::CONTENT_TYPE_NOT_SUPPORTED, fn () => $this->send(['parts' => [['url' => 'https://example.com/a']]]));

        self::assertSame('file (url)', $error['data'][0]['metadata']['mediaType']);
        self::assertSame('text/plain,application/json', $error['data'][0]['metadata']['supportedMediaTypes']);
    }

    public function testExplicitPlainTextAndJsonMediaTypesAreAccepted(): void
    {
        $task = $this->send(['parts' => [
            ['text' => 'robusto', 'mediaType' => 'text/plain; charset=utf-8'],
            ['data' => ['limit' => 1], 'mediaType' => 'application/json'],
            ['text' => '', 'mediaType' => ''],
        ]])['task'];

        self::assertSame(1, \count($task['artifacts'][0]['parts'][0]['data']['products']));
    }

    public function testGetTaskReturnsTheSameTask(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]], ['acceptedOutputModes' => ['text/plain']])['task'];

        self::assertSame($sent, $this->call('GetTask', ['id' => $sent['id']]));
        self::assertSame($sent, $this->call('GetTask', ['id' => $sent['id'], 'historyLength' => 3]));
    }

    public function testGetTaskWorksOnAnotherInstance(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]])['task'];

        // Another replica, another process: nothing but the secret is shared.
        $elsewhere = Catalogue::shop()->methods(now: Catalogue::NOW + 3600);

        self::assertSame($sent, $elsewhere->call('GetTask', ['id' => $sent['id']]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badTaskIds(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'a number' => [12];
        yield 'too long' => [str_repeat('t', TaskIdCodec::MAX_LENGTH + 1)];
    }

    #[DataProvider('badTaskIds')]
    public function testATaskIdIsRequired(mixed $id): void
    {
        $params = null === $id ? [] : ['id' => $id];

        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('GetTask', $params));
        $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('CancelTask', $params));
    }

    public function testAnUnknownTaskIsNotFound(): void
    {
        $error = $this->expectA2aError(A2aError::TASK_NOT_FOUND, fn () => $this->call('GetTask', ['id' => 'no-such-task']));

        self::assertSame('TASK_NOT_FOUND', $error['data'][0]['reason']);
        self::assertSame('a2a-protocol.org', $error['data'][0]['domain']);
        self::assertSame('no-such-task', $error['data'][0]['metadata']['taskId']);
        $this->expectA2aError(A2aError::TASK_NOT_FOUND, fn () => $this->call('CancelTask', ['id' => 'no-such-task']));
    }

    public function testAnExpiredTaskIsNotFound(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]])['task'];
        $later = Catalogue::shop()->methods(now: Catalogue::NOW + 86401);

        $this->expectA2aError(A2aError::TASK_NOT_FOUND, static fn () => $later->call('GetTask', ['id' => $sent['id']]));
    }

    public function testATaskOfAnotherChannelIsNotFound(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]])['task'];
        $other = new Catalogue('OTHER');

        $this->expectA2aError(A2aError::TASK_NOT_FOUND, static fn () => $other->methods()->call('GetTask', ['id' => $sent['id']]));
    }

    public function testATaskOfASkillThisAgentNoLongerHasIsNotFound(): void
    {
        $id = (new TaskIdCodec(Catalogue::SECRET))->encode(new TaskReference('retired', [], 'WEB', 'de_DE', 'c', Catalogue::NOW, ['text/plain'], 'n'));

        $this->expectA2aError(A2aError::TASK_NOT_FOUND, fn () => $this->call('GetTask', ['id' => $id]));
    }

    public function testCancelTaskFindsEveryTaskAlreadyCompleted(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]])['task'];
        $error = $this->expectA2aError(A2aError::TASK_NOT_CANCELABLE, fn () => $this->call('CancelTask', ['id' => $sent['id']]));

        self::assertSame('TASK_STATE_COMPLETED', $error['data'][0]['metadata']['state']);
    }

    public function testAMessageToAnExistingTaskIsRefusedBecauseTheTaskIsFinished(): void
    {
        $sent = $this->send(['parts' => [['text' => 'robusto']]])['task'];

        $this->expectA2aError(A2aError::UNSUPPORTED_OPERATION, fn () => $this->send(['taskId' => $sent['id'], 'parts' => [['text' => 'more']]]));
        $this->expectA2aError(A2aError::TASK_NOT_FOUND, fn () => $this->send(['taskId' => 'forged', 'parts' => [['text' => 'more']]]));
    }

    public function testListTasksListsNothingOnAPublicEndpoint(): void
    {
        self::assertSame(['tasks' => [], 'nextPageToken' => '', 'pageSize' => 50, 'totalSize' => 0], $this->call('ListTasks', []));
        self::assertSame(['tasks' => [], 'nextPageToken' => '', 'pageSize' => 7, 'totalSize' => 0], $this->call('ListTasks', [
            'pageSize' => 7, 'status' => 'TASK_STATE_WORKING', 'contextId' => 'c', 'pageToken' => '', 'historyLength' => 0,
        ]));
        self::assertSame(100, $this->call('ListTasks', ['pageSize' => 100])['pageSize']);
        self::assertSame(1, $this->call('ListTasks', ['pageSize' => 1])['pageSize']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badListParams(): iterable
    {
        yield 'zero page size' => [['pageSize' => 0], 'pageSize'];
        yield 'huge page size' => [['pageSize' => 101], 'pageSize'];
        yield 'string page size' => [['pageSize' => '10'], 'pageSize'];
        yield 'unknown status' => [['status' => 'DONE'], 'status'];
        yield 'foreign page token' => [['pageToken' => 'abc'], 'pageToken'];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('badListParams')]
    public function testListTasksValidatesItsParameters(array $params, string $field): void
    {
        $error = $this->expectA2aError(A2aError::INVALID_PARAMS, fn () => $this->call('ListTasks', $params));

        self::assertSame($field, $error['data'][0]['fieldViolations'][0]['field']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function undeclaredCapabilities(): iterable
    {
        yield 'streaming' => ['SendStreamingMessage', A2aError::UNSUPPORTED_OPERATION];
        yield 'resubscribe' => ['SubscribeToTask', A2aError::UNSUPPORTED_OPERATION];
        yield 'extended card' => ['GetExtendedAgentCard', A2aError::UNSUPPORTED_OPERATION];
        yield 'create push config' => ['CreateTaskPushNotificationConfig', A2aError::PUSH_NOTIFICATION_NOT_SUPPORTED];
        yield 'get push config' => ['GetTaskPushNotificationConfig', A2aError::PUSH_NOTIFICATION_NOT_SUPPORTED];
        yield 'list push configs' => ['ListTaskPushNotificationConfigs', A2aError::PUSH_NOTIFICATION_NOT_SUPPORTED];
        yield 'delete push config' => ['DeleteTaskPushNotificationConfig', A2aError::PUSH_NOTIFICATION_NOT_SUPPORTED];
        yield 'unknown' => ['message/send', A2aError::METHOD_NOT_FOUND];
    }

    #[DataProvider('undeclaredCapabilities')]
    public function testUndeclaredCapabilitiesAnswerTheErrorTheSpecPrescribes(string $method, int $code): void
    {
        $error = $this->expectA2aError($code, fn () => $this->call($method, []));

        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', $error['data'][0]['@type']);
    }

    public function testTheSkillsAreListedInTheOrderGiven(): void
    {
        self::assertSame(['product-search', 'product-detail'], array_map(static fn ($skill): string => $skill->id(), Catalogue::shop()->methods()->skills()));
    }

    public function testErrorsWithoutDetailsCarryNoDataMember(): void
    {
        self::assertSame(['code' => -32700, 'message' => 'Invalid JSON payload'], A2aError::parseError()->toArray());
        self::assertSame(['code' => -32603, 'message' => 'Internal error'], A2aError::internal()->toArray());
    }

    public function testErrorMetadataEchoedFromTheRequestIsClipped(): void
    {
        $error = A2aError::taskNotFound(str_repeat('x', 500))->toArray();

        self::assertSame(200, mb_strlen($error['data'][0]['metadata']['taskId']));
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    private function send(array $message, array $configuration = []): array
    {
        $params = ['message' => $this->message($message)];
        if ([] !== $configuration) {
            $params['configuration'] = $configuration;
        }

        return $this->call('SendMessage', $params);
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function message(array $message): array
    {
        return $message + ['messageId' => 'msg-1', 'role' => 'ROLE_USER'];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        return Catalogue::shop()->methods()->call($method, $params);
    }

    /**
     * @param callable(): mixed $call
     *
     * @return array<string, mixed> the error object
     */
    private function expectA2aError(int $code, callable $call): array
    {
        try {
            $call();
        } catch (A2aError $error) {
            self::assertSame($code, $error->getCode(), $error->getMessage());

            return $error->toArray();
        }
        self::fail(\sprintf('Expected A2A error %d.', $code));
    }

    /** @param array<string, mixed> $error */
    private function violation(array $error): string
    {
        return (string) $error['data'][0]['fieldViolations'][0]['description'];
    }
}
