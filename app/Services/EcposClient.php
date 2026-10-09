<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only access to the ECPOS external ordering API. The key never leaves the server. */
class EcposClient
{
    /**
     * POST JSON. Deliberately NOT retried: if the answer is lost the order may already exist, and sending again would duplicate it.
     *
     * @return array<int|string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $key = config('ecpos.api_key');
        if (! $key) {
            throw new RuntimeException('ECPOS_API_KEY is not set in .env.');
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => $key, 'Accept' => 'application/json'])
                ->asJson()
                ->timeout(config('ecpos.timeout'))
                ->post(rtrim(config('ecpos.base_url').'/'.ltrim($path, '/'), '/'), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach ECPOS, so the order was not sent. Please try again.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('ECPOS rejected the API key.');
        }
        if (! $response->successful()) {
            $why = $response->json('message') ?? $response->json('error') ?? null;

            throw new RuntimeException('ECPOS did not accept the order'.($why && is_string($why) ? ': '.$why : ' (HTTP '.$response->status().').'));
        }

        return is_array($response->json()) ? $response->json() : ['raw' => $response->body()];
    }

    /** @return array<int|string, mixed> */
    public function get(string $path): array
    {
        $key = config('ecpos.api_key');
        if (! $key) {
            throw new RuntimeException('ECPOS_API_KEY is not set in .env.');
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => $key, 'Accept' => 'application/json'])
                ->timeout(config('ecpos.timeout'))
                ->retry(2, 500, throw: false)
                ->get(config('ecpos.base_url').'/'.ltrim($path, '/'));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach ECPOS. Please try again in a moment.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('ECPOS rejected the API key.');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('ECPOS returned an unexpected answer (HTTP '.$response->status().').');
        }

        return $response->json();
    }
}
