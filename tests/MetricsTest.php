<?php
namespace ReadMe\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReadMe\Metrics;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;

class MetricsTest extends \PHPUnit\Framework\TestCase
{
    /** @var Metrics */
    private $metrics;

    /** @var \Closure */
    private $metrics_group;

    // ?val=1&arr[]=&arr[]=3
    private $test_query_params = [
        'val' => '1',
        'arr' => [null, '3'],
    ];

    private $test_post_params = [
        'password' => '123456',
        'apiKey' => 'abcdef',
        'another' => 'Hello world'
    ];

    private $test_files_params = [
        'testfileparam' => [
          'name' => 'owlbert',
          'type' => 'application/octet-stream',
          'tmp_name' => __DIR__ . '/fixtures/owlbert.png',
          'error' => 0,
          'size' => 701048
        ]
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        define('LARAVEL_START', microtime(true));
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics_group = function (Request $request): array {
            return [
                'id' => '123457890',
                'label' => 'username',
                'email' => 'email@example.com'
            ];
        };

        $this->metrics = new Metrics('fakeApiKey', $this->metrics_group);
    }

    public function testTrack(): void
    {
        $this->markTestIncomplete();
        // $this->metrics->track();
    }

    public function testConstructPayload(): void
    {
        $request = $this->getMockRequest($this->test_query_params);
        $response = $this->getMockJsonResponse();
        $payload = $this->metrics->constructPayload($request, $response);

        $this->assertSame([
            'id' => '123457890',
            'label' => 'username',
            'email' => 'email@example.com'
        ], $payload['group']);

        $this->assertSame('8.8.8.8', $payload['clientIPAddress']);
        $this->assertFalse($payload['development']);

        $this->assertSame('readme/metrics', $payload['request']['log']['creator']['name']);
        $this->assertIsString($payload['request']['log']['creator']['version']);
        $this->assertSame(PHP_OS_FAMILY . '/php v' . PHP_VERSION, $payload['request']['log']['creator']['comment']);

        $this->assertCount(1, $payload['request']['log']['entries']);

        $log_entry = $payload['request']['log']['entries'][0];
        $this->assertSame($request->url(), $log_entry['pageref']);
        $this->assertRegExp(
            '/(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})\+(\d{2}:\d{2})/',
            $log_entry['startedDateTime'],
            'startedDateTime was not in a format matching `2019-12-19T01:17:51+00:00`.'
        );

        $this->assertIsFloat($log_entry['time']);
        $this->assertIsNumeric($log_entry['time']);
        $this->assertGreaterThan(0, $log_entry['time']);

        // Assert that the request was set up properly.
        $log_request = $log_entry['request'];
        $this->assertSame($request->method(), $log_request['method']);
        $this->assertSame($request->fullUrl(), $log_request['url']);
        $this->assertSame('HTTP/1.1', $log_request['httpVersion']);

        $this->assertSame([
            ['name' => 'cache-control', 'value' => 'max-age=0'],
            ['name' => 'user-agent', 'value' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) ...']
        ], $log_request['headers']);

        $this->assertSame([
            ['name' => 'val', 'value' => '1'],
            ['name' => 'arr', 'value' => '[null,"3"]']
        ], $log_request['queryString']);

        $this->assertSame('application/json', $log_request['postData']['mimeType']);
        $this->assertEmpty(
            $log_request['postData']['params'],
            'postData should not have any data here because there is none for this GET request'
        );

        // Assert that the response was set as expected into the payload.
        $log_response = $log_entry['response'];
        $this->assertSame(200, $log_response['status']);
        $this->assertSame('OK', $log_response['statusText']);

        $this->assertSame([
            ['name' => 'cache-control', 'value' => 'no-cache, private'],
            ['name' => 'content-type', 'value' => 'application/json'],
            ['name' => 'x-ratelimit-limit', 'value' => 60],
            ['name' => 'x-ratelimit-remaining', 'value' => 58]
        ], $log_response['headers']);

        $this->assertSame('"[\"value 1\", \"value 2\", \"value 3\"]"', $log_response['content']['text']);
        $this->assertSame($response->headers->get('Content-Length', 0), $log_response['content']['size']);
        $this->assertSame($response->headers->get('Content-Type'), $log_response['content']['mimeType']);
    }

    public function testConstructPayloadWithNonJsonResponse(): void
    {
        $this->markTestIncomplete();
    }

    public function testConstructPayloadWithUploadFileInRequest(): void
    {
        $request = $this->getMockRequest([], $this->test_post_params, $this->test_files_params);
        $response = $this->getMockJsonResponse();
        $payload = $this->metrics->constructPayload($request, $response);

        $params = $payload['request']['log']['entries'][0]['request']['postData']['params'];
        $this->assertSame([
            [
                'name' => 'password',
                'value' => '123456',
            ],
            [
                "name" => "apiKey",
                "value" => "abcdef"
            ],
            [
                "name" => "another",
                "value" => "Hello world"
            ],
            [
                "name" => "testfileparam",
                'value' => file_get_contents($this->test_files_params['testfileparam']['tmp_name']),
                "fileName" => "owlbert",
                "contentType" => "image/png"
            ]
        ], $params);
    }

    public function testConstructPayloadShouldThrowErrorIfGroupFunctionDoesNotReturnExpectedPayload(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/did not return an array with an id present/');

        $request = \Mockery::mock(Request::class);
        $response = \Mockery::mock(JsonResponse::class);

        (new Metrics('fakeApiKey', function (): array {
            return [];
        }))->constructPayload($request, $response);
    }

    public function testConstructPayloadShouldThrowErrorIfGroupFunctionReturnsAnEmptyId(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessageMatches('/must not return an empty id/');

        $request = \Mockery::mock(Request::class);
        $response = \Mockery::mock(JsonResponse::class);

        (new Metrics('fakeApiKey', function (): array {
            return ['id' => ''];
        }))->constructPayload($request, $response);
    }

    public function testProcessRequestShouldFilterOutItemsInBlacklist(): void
    {
        $metrics = new Metrics('fakeApiKey', $this->metrics_group, [
            'blacklist' => ['val', 'password']
        ]);

        $request = $this->getMockRequest($this->test_query_params, $this->test_post_params);
        $response = $this->getMockJsonResponse();
        $payload = $metrics->constructPayload($request, $response);

        $request_data = $payload['request']['log']['entries'][0]['request'];

        // Blacklist should not affect $_GET params.
        $this->assertSame([
            ['name' => 'val', 'value' => '1'],
            ['name' => 'arr', 'value' => '[null,"3"]']
        ], $request_data['queryString']);

        $params = $request_data['postData']['params'];
        $this->assertSame([
            ['name' => 'apiKey', 'value' => 'abcdef'],
            ['name' => 'another', 'value' => 'Hello world']
        ], $params);
    }

    public function testProcessRequestShouldFilterOnlyItemsInWhitelist(): void
    {
        $metrics = new Metrics('fakeApiKey', $this->metrics_group, [
            'whitelist' => ['val', 'password']
        ]);

        $request = $this->getMockRequest($this->test_query_params, $this->test_post_params);
        $response = $this->getMockJsonResponse();
        $payload = $metrics->constructPayload($request, $response);

        $request_data = $payload['request']['log']['entries'][0]['request'];

        // Whitelist should not affect $_GET params.
        $this->assertSame([
            ['name' => 'val', 'value' => '1'],
            ['name' => 'arr', 'value' => '[null,"3"]']
        ], $request_data['queryString']);

        $params = $request_data['postData']['params'];
        $this->assertSame([
            ['name' => 'password', 'value' => '123456']
        ], $params);
    }

    public function testProcessResponseShouldFilterOutItemsInBlacklist(): void
    {
        $this->markTestIncomplete();
    }

    public function testProcessResponseShouldFilterOnlyItemsInWhitelist(): void
    {
        $this->markTestIncomplete();
    }

    private function getMockRequest($query_params = [], $post_params = [], $file_params = []): Request
    {
        $_GET = $query_params;
        $_POST = $post_params;
        $_FILES = $file_params;

        $request = \Mockery::mock(Request::class, [
            'ip' => '8.8.8.8',
            'url' => 'http://api.example.com/v1/user',
            'method' => 'GET',
            'fullUrl' => 'http://api.example.com/v1/user' .
                (!empty($query_params)) ? '?' . http_build_query($query_params) : null
        ])->makePartial();

        $request->headers = \Mockery::mock(HeaderBag::class, [
            'all' => [
                'cache-control' => ['max-age=0'],
                'user-agent' => [
                    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_5) ...'
                ]
            ]
        ]);

        return $request;
    }

    private function getMockJsonResponse(): JsonResponse
    {
        $response = \Mockery::mock(JsonResponse::class, [
            'getData' => '["value 1", "value 2", "value 3"]',
            'getStatusCode' => 200,
        ]);

        $response->headers = \Mockery::mock(HeaderBag::class, [
            'all' => [
                'cache-control' => ['no-cache, private'],
                'content-type' => ['application/json'],
                'x-ratelimit-limit' => [60],
                'x-ratelimit-remaining' => [58]
            ]
        ]);

        $response->headers->shouldReceive('get')->withArgs(['Content-Length', 0])->andReturn(33);
        $response->headers->shouldReceive('get')->withArgs(['Content-Type'])->andReturn('application/json');

        return $response;
    }
}
