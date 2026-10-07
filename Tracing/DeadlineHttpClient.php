<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tracing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Uses the remaining drain budget, including time already spent serializing earlier batches. */
final readonly class DeadlineHttpClient implements ClientInterface
{
    public function __construct(private HttpClientInterface $client, private ExportBudget $budget) {}

    #[Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $remaining = $this->budget->remainingSeconds();
        if ($remaining < 0.001) {
            throw new RuntimeException('Trace export budget exhausted.');
        }

        $factory = new Psr17Factory;
        $client = new Psr18Client($this->client->withOptions([
            'timeout' => $remaining,
            'max_duration' => $remaining,
            'max_redirects' => 0,
        ]), $factory, $factory);
        $response = $client->sendRequest($request);
        $response->getBody()->getContents();
        $response->getBody()->rewind();

        return $response;
    }
}
