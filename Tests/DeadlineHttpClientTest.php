<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Storm\Telemetry\Tracing\DeadlineHttpClient;
use Storm\Telemetry\Tracing\ExportBudget;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class DeadlineHttpClientTest extends TestCase
{
    #[Test]
    public function exactly_one_millisecond_left_still_sends(): void
    {
        // the refusal sits strictly below curl's millisecond resolution: one millisecond left is still a
        // timeout curl can honor
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse('');
        });

        new DeadlineHttpClient($client, $this->startedBudget(1))->sendRequest($this->request());

        self::assertSame(1, $calls);
    }

    #[Test]
    public function the_remaining_budget_bounds_the_whole_request_and_no_redirect_is_followed(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$options['timeout'], $options['max_duration'], $options['max_redirects']];

            return new MockResponse('');
        });

        new DeadlineHttpClient($client, $this->startedBudget(200))->sendRequest($this->request());

        self::assertSame([0.2, 0.2, 0], $seen);
    }

    #[Test]
    public function the_caller_reads_the_drained_body_from_its_start(): void
    {
        $response = new DeadlineHttpClient(new MockHttpClient(new MockResponse('success')), $this->startedBudget(200))
            ->sendRequest($this->request());

        self::assertSame('success', $response->getBody()->getContents());
    }

    #[Test]
    public function the_body_is_drained_inside_the_budget_so_a_late_reader_still_gets_it(): void
    {
        // headers first, the body twenty milliseconds later and still inside the budget: drained here, it
        // survives a caller that reads only once the deadline has passed, where curl would time out
        $server = new Process([PHP_BINARY, __DIR__.'/Fixture/SlowTraceServer.php', 'split']);
        $server->start();
        try {
            self::assertTrue($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY ')));
            preg_match('/READY ([0-9]+)/', $server->getOutput(), $match);
            $budget = new ExportBudget(500);
            $budget->start();
            $response = new DeadlineHttpClient(new CurlHttpClient, $budget)->sendRequest(
                new Psr17Factory()->createRequest('POST', 'http://127.0.0.1:'.($match[1] ?? throw new RuntimeException('Server did not report a port.')).'/v1/traces'),
            );
            usleep(700_000);

            self::assertSame('ok', $response->getBody()->getContents());
        } finally {
            $server->stop(0);
        }
    }

    private function startedBudget(int $milliseconds): ExportBudget
    {
        $budget = new ExportBudget($milliseconds, static fn (): int => 0);
        $budget->start();

        return $budget;
    }

    private function request(): RequestInterface
    {
        return new Psr17Factory()->createRequest('POST', 'http://localhost/v1/traces');
    }
}
