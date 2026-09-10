<?php

namespace App\Services\Cloudflare;

use App\Models\CloudflareSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CloudflareClient
{
    public function __construct(
        private readonly string $token,
    ) {}

    public static function fromSettings(CloudflareSetting $settings): self
    {
        return new self((string) $settings->api_token);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyToken(): array
    {
        $result = $this->decode($this->http()->get('/user/tokens/verify'));

        return is_array($result) ? $result : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listZones(?string $accountId = null, ?string $name = null, int $perPage = 50): array
    {
        $query = ['per_page' => $perPage];
        if (filled($accountId)) {
            $query['account.id'] = $accountId;
        }
        if (filled($name)) {
            $query['name'] = $name;
        }

        $result = $this->decode($this->http()->get('/zones', $query));

        return $this->asList($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function createZone(string $accountId, string $name): array
    {
        $result = $this->decode($this->http()->post('/zones', [
            'account' => ['id' => $accountId],
            'name' => $name,
            'type' => 'full',
        ]));

        return is_array($result) ? $result : [];
    }

    /**
     * Incomplete POST used only to infer Zone Edit. Never send a zone name.
     */
    public function probeZoneCreate(string $accountId): Response
    {
        return $this->http()->post('/zones', [
            'account' => ['id' => $accountId],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function listDnsRecords(string $zoneId, array $query = []): array
    {
        $result = $this->decode($this->http()->get('/zones/'.$zoneId.'/dns_records', $query));

        return $this->asList($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDnsRecord(string $zoneId, array $payload): array
    {
        $result = $this->decode($this->http()->post('/zones/'.$zoneId.'/dns_records', $payload));

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDnsRecord(string $zoneId, string $recordId, array $payload): array
    {
        $result = $this->decode($this->http()->put('/zones/'.$zoneId.'/dns_records/'.$recordId, $payload));

        return is_array($result) ? $result : [];
    }

    /**
     * Incomplete POST used only to infer DNS Edit.
     */
    public function probeDnsCreate(string $zoneId): Response
    {
        return $this->http()->post('/zones/'.$zoneId.'/dns_records', []);
    }

    public function rawGet(string $path, array $query = []): Response
    {
        return $this->http()->get($path, $query);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ops.cloudflare.api_base'), '/'))
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ops.cloudflare.timeout', 20));
    }

    private function decode(Response $response): mixed
    {
        if ($response->failed()) {
            throw CloudflareApiException::fromResponse($response, $this->token);
        }

        $json = $response->json();
        if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
            throw CloudflareApiException::fromResponse($response, $this->token);
        }

        if (is_array($json) && array_key_exists('result', $json)) {
            return $json['result'];
        }

        return $json;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function asList(mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        if ($result === []) {
            return [];
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, is_array(...)));
        }

        return [];
    }
}
