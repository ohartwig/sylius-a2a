<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Task;

use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusA2a\Task\TaskReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A task id is the signed task. Whatever was not signed by this shop is not a
 * task of this shop.
 */
#[CoversClass(TaskIdCodec::class)]
#[CoversClass(TaskReference::class)]
final class TaskIdCodecTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testAnIdDecodesToTheReferenceItWasMadeFrom(): void
    {
        $reference = $this->reference();
        $id = $this->codec()->encode($reference);

        self::assertStringStartsWith('t1.', $id);
        self::assertMatchesRegularExpression('/^t1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{22}$/', $id);
        self::assertEquals($reference, $this->codec()->decode($id, self::NOW));
    }

    public function testTheNonceMakesEqualTasksDistinct(): void
    {
        self::assertNotSame(
            $this->codec()->encode($this->reference(nonce: 'a')),
            $this->codec()->encode($this->reference(nonce: 'b')),
        );
    }

    public function testAnotherSecretDoesNotRecogniseTheId(): void
    {
        $id = $this->codec()->encode($this->reference());

        self::assertNull((new TaskIdCodec('another-secret'))->decode($id, self::NOW));
    }

    public function testATamperedPayloadIsRejected(): void
    {
        $id = $this->codec()->encode($this->reference());
        [$prefix, $payload, $signature] = explode('.', $id);
        $json = (string) base64_decode(strtr($payload, '-_', '+/'));
        $forged = rtrim(strtr(base64_encode(str_replace('robusto', 'corona!', $json)), '+/', '-_'), '=');

        self::assertNull($this->codec()->decode($prefix.'.'.$forged.'.'.$signature, self::NOW));
    }

    public function testTheIdLivesForItsTtlAndNotASecondLonger(): void
    {
        $codec = new TaskIdCodec('secret', 600);
        $id = $codec->encode($this->reference());

        self::assertNotNull($codec->decode($id, self::NOW + 600));
        self::assertNull($codec->decode($id, self::NOW + 601));
        // From the future: a clock skew or a forgery, either way not ours.
        self::assertNull($codec->decode($id, self::NOW - 1));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'no prefix' => ['abc.def'];
        yield 'one part' => ['t1.abc'];
        yield 'three parts' => ['t1.a.b.c'];
        yield 'too long' => ['t1.'.str_repeat('a', TaskIdCodec::MAX_LENGTH)];
    }

    #[DataProvider('malformedIds')]
    public function testMalformedIdsAreRejected(string $id): void
    {
        self::assertNull($this->codec()->decode($id, self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function signedButInvalidPayloads(): iterable
    {
        yield 'not base64 json' => ['!!!'];
        yield 'not an object' => [self::b64('"x"')];
        yield 'missing fields' => [self::b64('{"s":"product-search"}')];
        yield 'wrong types' => [self::b64('{"s":"x","a":[],"h":"WEB","l":"de_DE","c":"c","t":"1800000000","m":[],"n":"n"}')];
        yield 'too deep' => [self::b64('{"s":"x","a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":1}}}}}}},"h":"WEB","l":"de_DE","c":"c","t":1800000000,"m":[],"n":"n"}')];
    }

    /** A payload with a valid signature that still is no reference — only a leaked secret produces one. */
    #[DataProvider('signedButInvalidPayloads')]
    public function testSignedPayloadsThatAreNoReferenceAreRejected(string $payload): void
    {
        $signature = rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 't1.'.$payload, 'secret', true), 0, 16)), '+/', '-_'), '=');

        self::assertNull($this->codec()->decode('t1.'.$payload.'.'.$signature, self::NOW));
    }

    public function testOnlyStringOutputModesSurvive(): void
    {
        $payload = self::b64('{"s":"x","a":{},"h":"WEB","l":"de_DE","c":"c","t":1800000000,"m":["text/plain",3],"n":"n"}');
        $signature = rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 't1.'.$payload, 'secret', true), 0, 16)), '+/', '-_'), '=');

        self::assertSame(['text/plain'], $this->codec()->decode('t1.'.$payload.'.'.$signature, self::NOW)?->outputModes);
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskIdCodec('');
    }

    private static function b64(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function codec(): TaskIdCodec
    {
        return new TaskIdCodec('secret');
    }

    private function reference(string $nonce = 'abc'): TaskReference
    {
        return new TaskReference(
            skillId: 'product-search',
            arguments: ['query' => 'robusto', 'limit' => 10, 'offset' => 0],
            channelCode: 'WEB',
            localeCode: 'de_DE',
            contextId: 'ctx',
            createdAt: self::NOW,
            outputModes: ['application/json', 'text/plain'],
            nonce: $nonce,
        );
    }
}
