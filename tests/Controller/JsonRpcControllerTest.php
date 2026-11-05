<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Tests\Controller;

use Koh\SyliusA2a\Controller\JsonRpcController;
use Koh\SyliusA2a\JsonRpc\A2aMethods;
use Koh\SyliusA2a\Skill\A2aSkillInterface;
use Koh\SyliusA2a\Skill\SkillInput;
use Koh\SyliusA2a\Skill\SkillResult;
use Koh\SyliusA2a\Task\TaskIdCodec;
use Koh\SyliusA2a\Tests\Fixture\Catalogue;
use Koh\SyliusAgentContracts\Context\AgentContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The JSON-RPC binding over HTTP: framing, version negotiation and the bounds
 * of a public endpoint.
 */
#[CoversClass(JsonRpcController::class)]
#[CoversClass(\Koh\SyliusA2a\JsonRpc\A2aError::class)]
final class JsonRpcControllerTest extends TestCase
{
    public function testASendMessageCallIsAnsweredWithItsIdAndATask(): void
    {
        $response = $this->post($this->sendMessage('robusto', id: 'req-1'));
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame('req-1', $body['id']);
        self::assertArrayNotHasKey('error', $body);
        self::assertSame('TASK_STATE_COMPLETED', $body['result']['task']['status']['state']);
    }

    public function testNumericAndNullIdsAreEchoed(): void
    {
        self::assertSame(7, $this->decode($this->post($this->sendMessage('robusto', id: 7)))['id']);
        self::assertNull($this->decode($this->post($this->sendMessage('robusto', id: null)))['id']);
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $content = (string) $this->post($this->sendMessage('robusto'))->getContent();

        self::assertStringContainsString('https://shop.example.com/de_DE/products/robusto-especial', $content);
        self::assertStringContainsString('€', $content);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedVersions(): iterable
    {
        yield 'major.minor' => ['1.0'];
        yield 'with patch' => ['1.0.1'];
        yield 'padded' => [' 1.0 '];
    }

    #[DataProvider('acceptedVersions')]
    public function testVersion10IsSpokenWhateverThePatch(string $version): void
    {
        self::assertArrayHasKey('result', $this->decode($this->post($this->sendMessage('robusto'), version: $version)));
    }

    public function testTheVersionMayComeAsAQueryParameter(): void
    {
        $request = Request::create('/api/agent/a2a?A2A-Version=1.0', 'POST', content: $this->sendMessage('robusto'));

        self::assertArrayHasKey('result', $this->decode($this->controller()($request)));
    }

    public function testTheHeaderWinsOverTheQueryParameter(): void
    {
        $request = Request::create('/api/agent/a2a?A2A-Version=1.0', 'POST', server: ['HTTP_A2A_VERSION' => '2.0'], content: $this->sendMessage('robusto'));

        self::assertSame(-32009, $this->decode($this->controller()($request))['error']['code']);
    }

    /**
     * §3.6.2: an absent version means 0.3. This endpoint speaks 1.0 only, so a
     * client that does not say which version it speaks is told so.
     */
    public function testWithoutAVersionTheCallerIsTakenForA03ClientAndRefused(): void
    {
        $body = $this->decode($this->post($this->sendMessage('robusto', id: 3), version: null));

        self::assertSame(3, $body['id']);
        self::assertSame(-32009, $body['error']['code']);
        self::assertSame('VERSION_NOT_SUPPORTED', $body['error']['data'][0]['reason']);
        self::assertSame('0.3 (no A2A-Version header)', $body['error']['data'][0]['metadata']['requestedVersion']);
        self::assertSame('1.0', $body['error']['data'][0]['metadata']['supportedVersions']);
    }

    public function testABlankVersionHeaderCountsAsNone(): void
    {
        $body = $this->decode($this->post($this->sendMessage('robusto'), version: '  '));

        self::assertSame('0.3 (no A2A-Version header)', $body['error']['data'][0]['metadata']['requestedVersion']);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedVersions(): iterable
    {
        yield '0.3' => ['0.3'];
        yield '1.1' => ['1.1'];
        yield '2.0.0' => ['2.0.0'];
        yield 'garbage' => ['latest'];
        yield 'prefixed' => ['v1.0'];
        yield 'suffixed' => ['1.0-rc'];
    }

    #[DataProvider('refusedVersions')]
    public function testOtherVersionsAreRefused(string $version): void
    {
        $body = $this->decode($this->post($this->sendMessage('robusto'), version: $version));

        self::assertSame(-32009, $body['error']['code']);
        self::assertSame('Protocol version not supported', $body['error']['message']);
    }

    public function testInvalidJsonIsAParseError(): void
    {
        $body = $this->decode($this->post('{"jsonrpc": "2.0",'));

        self::assertSame('2.0', $body['jsonrpc']);
        self::assertNull($body['id']);
        self::assertSame(['code' => -32700, 'message' => 'Invalid JSON payload'], $body['error']);
    }

    public function testNestingBeyondTheLimitIsAParseError(): void
    {
        $deep = str_repeat('[', 40).str_repeat(']', 40);

        self::assertSame(-32700, $this->decode($this->post('{"jsonrpc":"2.0","id":1,"method":"GetTask","params":{"x":'.$deep.'}}'))['error']['code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'a scalar' => ['42', 'the request must be a JSON-RPC 2.0 request object'];
        yield 'an empty object' => ['{}', 'the request must be a JSON-RPC 2.0 request object'];
        yield 'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ListTasks"}]', 'batch requests are not supported'];
        yield 'an object id' => ['{"jsonrpc":"2.0","id":{},"method":"ListTasks"}', 'id must be a string, a number or null'];
        yield 'a fractional id' => ['{"jsonrpc":"2.0","id":1.5,"method":"ListTasks"}', 'id must be a string, a number or null'];
        yield 'no jsonrpc member' => ['{"id":1,"method":"ListTasks"}', 'jsonrpc must be "2.0"'];
        yield 'jsonrpc 1.0' => ['{"jsonrpc":"1.0","id":1,"method":"ListTasks"}', 'jsonrpc must be "2.0"'];
        yield 'no method' => ['{"jsonrpc":"2.0","id":1}', 'method must be a non-empty string'];
        yield 'empty method' => ['{"jsonrpc":"2.0","id":1,"method":""}', 'method must be a non-empty string'];
        yield 'positional params' => ['{"jsonrpc":"2.0","id":1,"method":"ListTasks","params":[1]}', 'params must be an object'];
        yield 'scalar params' => ['{"jsonrpc":"2.0","id":1,"method":"ListTasks","params":"x"}', 'params must be an object'];
    }

    #[DataProvider('invalidRequests')]
    public function testAMalformedRequestIsAnInvalidRequest(string $body, string $reason): void
    {
        $error = $this->decode($this->post($body))['error'];

        self::assertSame(-32600, $error['code']);
        self::assertSame('Request payload validation error', $error['message']);
        self::assertSame($reason, $error['data'][0]['metadata']['reason']);
    }

    public function testTheIdOfAnInvalidRequestIsEchoedOnceItIsKnown(): void
    {
        self::assertSame(9, $this->decode($this->post('{"jsonrpc":"1.0","id":9,"method":"x"}'))['id']);
    }

    public function testAMissingParamsMemberIsAnEmptyObject(): void
    {
        $body = $this->decode($this->post('{"jsonrpc":"2.0","id":1,"method":"ListTasks"}'));

        self::assertSame(0, $body['result']['totalSize']);
    }

    public function testANotificationIsNotAnswered(): void
    {
        // A failing skill proves nothing ran: had it, the answer would be an error.
        $response = $this->controller($this->failingMethods())($this->request('{"jsonrpc":"2.0","method":"SendMessage","params":{}}'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testAnUnknownMethodIsMethodNotFound(): void
    {
        $error = $this->decode($this->post('{"jsonrpc":"2.0","id":1,"method":"tasks/get","params":{}}'))['error'];

        self::assertSame(-32601, $error['code']);
        self::assertSame('tasks/get', $error['data'][0]['metadata']['method']);
    }

    public function testAnA2aErrorIsReturnedAsTheJsonRpcErrorWithStatus200(): void
    {
        $response = $this->post('{"jsonrpc":"2.0","id":"x","method":"GetTask","params":{"id":"nope"}}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(-32001, $this->decode($response)['error']['code']);
    }

    public function testAnUnexpectedFailureIsAnInternalErrorAndIsLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level.': '.str_replace('{message}', (string) ($context['message'] ?? ''), (string) $message);
            }
        };
        $controller = new JsonRpcController($this->failingMethods(), logger: $logger);

        $body = $this->decode($controller($this->request($this->sendMessage('robusto', id: 5))));

        self::assertSame(5, $body['id']);
        self::assertSame(['code' => -32603, 'message' => 'Internal error'], $body['error']);
        self::assertSame(['error: A2A call failed: database is gone'], $logger->messages);
    }

    public function testAnUnexpectedFailureWithoutALoggerIsStillAnInternalError(): void
    {
        $controller = new JsonRpcController($this->failingMethods());

        self::assertSame(-32603, $this->decode($controller($this->request($this->sendMessage('robusto'))))['error']['code']);
    }

    public function testAnOversizedBodyIsRefusedBeforeItIsParsed(): void
    {
        $controller = new JsonRpcController(Catalogue::shop()->methods(), maxRequestBytes: 1024);
        $response = $controller($this->request($this->sendMessage(str_repeat('a', 1100))));
        $body = $this->decode($response);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(-32600, $body['error']['code']);
        self::assertSame('REQUEST_TOO_LARGE', $body['error']['data'][0]['reason']);
        self::assertSame('1024', $body['error']['data'][0]['metadata']['maxBytes']);
    }

    public function testADeclaredContentLengthOverTheLimitIsEnough(): void
    {
        $controller = new JsonRpcController(Catalogue::shop()->methods(), maxRequestBytes: 1024);
        $request = $this->request($this->sendMessage('robusto'));
        $request->headers->set('Content-Length', '1025');

        self::assertSame(413, $controller($request)->getStatusCode());
    }

    public function testABodyOfExactlyTheLimitIsAccepted(): void
    {
        $body = $this->sendMessage('robusto');
        $controller = new JsonRpcController(Catalogue::shop()->methods(), maxRequestBytes: \strlen($body));
        $request = $this->request($body);
        $request->headers->set('Content-Length', (string) \strlen($body));

        self::assertSame(200, $controller($request)->getStatusCode());
    }

    public function testAClientOverItsRateLimitIsToldWhenToComeBack(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $controller = new JsonRpcController(Catalogue::shop()->methods(), $limiter);

        self::assertSame(200, $controller($this->request($this->sendMessage('robusto')))->getStatusCode());
        self::assertSame(200, $controller($this->request($this->sendMessage('robusto')))->getStatusCode());
        $refused = $controller($this->request($this->sendMessage('robusto')));
        $body = $this->decode($refused);

        self::assertSame(429, $refused->getStatusCode());
        self::assertGreaterThanOrEqual(1, (int) $refused->headers->get('Retry-After'));
        // The window is a minute; the limiter rounds its start to the second.
        self::assertLessThanOrEqual(61, (int) $refused->headers->get('Retry-After'));
        self::assertNull($body['id']);
        self::assertSame(-32603, $body['error']['code']);
        self::assertSame('Rate limit exceeded', $body['error']['message']);
        self::assertSame($refused->headers->get('Retry-After'), $body['error']['data'][0]['metadata']['retryAfterSeconds']);

        // Another client address has its own budget.
        $other = $this->request($this->sendMessage('robusto'));
        $other->server->set('REMOTE_ADDR', '192.0.2.99');
        self::assertSame(200, $controller($other)->getStatusCode());
    }

    public function testAPreflightIsAnsweredForAnyOrigin(): void
    {
        $response = $this->controller()(Request::create('/api/agent/a2a', 'OPTIONS'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, A2A-Version, A2A-Extensions', $response->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('86400', $response->headers->get('Access-Control-Max-Age'));
    }

    public function testAPreflightIsNotCountedAgainstTheRateLimit(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage());
        $controller = new JsonRpcController(Catalogue::shop()->methods(), $limiter);

        $controller(Request::create('/api/agent/a2a', 'OPTIONS'));

        self::assertSame(200, $controller($this->request($this->sendMessage('robusto')))->getStatusCode());
    }

    public function testADisabledEndpointDoesNotExist(): void
    {
        $controller = new JsonRpcController(Catalogue::shop()->methods(), enabled: false);

        self::assertSame(404, $controller($this->request($this->sendMessage('robusto')))->getStatusCode());
        self::assertSame(404, $controller(Request::create('/api/agent/a2a', 'OPTIONS'))->getStatusCode());
    }

    public function testThePathIsUnderTheAgentApiPrefix(): void
    {
        // Pinned literally: installations keep /api/agent/ out of the session
        // firewall, and the card and the api-catalog announce this path.
        self::assertSame('/api/agent/a2a', JsonRpcController::PATH);
    }

    private function controller(?A2aMethods $methods = null): JsonRpcController
    {
        return new JsonRpcController($methods ?? Catalogue::shop()->methods());
    }

    private function post(string $body, ?string $version = '1.0'): Response
    {
        return $this->controller()($this->request($body, $version));
    }

    private function request(string $body, ?string $version = '1.0'): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.1'];
        if (null !== $version) {
            $server['HTTP_A2A_VERSION'] = $version;
        }

        return Request::create('/api/agent/a2a', 'POST', server: $server, content: $body);
    }

    private function sendMessage(string $text, int|string|null $id = 1): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'SendMessage',
            'params' => ['message' => ['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => $text]]]],
        ], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function failingMethods(): A2aMethods
    {
        $skill = new class implements A2aSkillInterface {
            public function id(): string
            {
                return 'product-search';
            }

            public function card(): array
            {
                return ['id' => 'product-search', 'name' => 'n', 'description' => 'd', 'tags' => ['t']];
            }

            public function arguments(SkillInput $input): array
            {
                return [];
            }

            public function run(array $arguments, AgentContext $context, string $localeCode): SkillResult
            {
                throw new \RuntimeException('database is gone');
            }
        };

        return new A2aMethods([$skill], Catalogue::shop()->contextFactory(), new TaskIdCodec('s'));
    }

}
