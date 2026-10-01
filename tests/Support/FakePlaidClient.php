<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Support;

use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\Client\PlaidResponse;
use Buckmerce\Plaid\Plaid\PlaidEnvironment;

/** Scripted Plaid client for service-level tests. */
final class FakePlaidClient implements PlaidClientInterface
{
    /** @var list<array{path:string, body:array<string, mixed>}> */
    public array $calls = array();

    /** @var array<string, list<callable(array<string, mixed>): PlaidResponse>> */
    private array $responses = array();

    public function __construct(private readonly string $environment = PlaidEnvironment::SANDBOX)
    {
    }

    /** @param callable(array<string, mixed>): PlaidResponse $responder */
    public function on(string $path, callable $responder): self
    {
        $this->responses[$path][] = $responder;
        return $this;
    }

    public function post(string $path, array $body): PlaidResponse
    {
        $this->calls[] = array('path' => $path, 'body' => $body);
        $queue = $this->responses[$path] ?? array();
        if (array() === $queue) {
            throw new \LogicException('Unexpected Plaid call: ' . $path);
        }
        $responder = count($queue) > 1 ? array_shift($this->responses[$path]) : $queue[0];
        return $responder($body);
    }

    public function environment(): PlaidEnvironment
    {
        return PlaidEnvironment::from_string($this->environment);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $call): string => $call['path'], $this->calls);
    }
}
