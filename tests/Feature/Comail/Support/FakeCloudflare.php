<?php

namespace Tests\Feature\Comail\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * In-memory Cloudflare zones + DNS records for Http::fake.
 */
class FakeCloudflare
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    /** @var array<string, string> zone name => id */
    public array $zones = [];

    /** @var array<string, array<string, mixed>> record id => record */
    public array $records = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $writes = [];

    private int $nextId = 1;

    public function fake(): array
    {
        return [self::BASE.'/*' => fn (Request $request): PromiseInterface => $this->handle($request)];
    }

    public function zone(string $name): string
    {
        $id = md5($name);
        $this->zones[$name] = $id;

        return $id;
    }

    public function record(string $zoneName, string $type, string $name, string $content, ?int $priority = null): string
    {
        $id = 'r'.$this->nextId++;
        $this->records[$id] = array_filter([
            'id' => $id,
            'zone_id' => $this->zones[$zoneName],
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'priority' => $priority,
        ], static fn (mixed $value): bool => $value !== null);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function at(string $name, ?string $type = null): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => strcasecmp($record['name'], $name) === 0 && ($type === null || $record['type'] === $type),
        ));
    }

    public function handle(Request $request): PromiseInterface
    {
        $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/client/v4'));
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($request->method() === 'GET' && $path === '/zones') {
            $name = (string) ($query['name'] ?? '');
            $result = [];
            foreach ($this->zones as $zoneName => $id) {
                if ($name === '' || $name === $zoneName) {
                    $result[] = ['id' => $id, 'name' => $zoneName, 'status' => 'active', 'name_servers' => []];
                }
            }

            return $this->ok($result);
        }

        if ($request->method() === 'GET' && preg_match('~^/zones/([^/]+)$~', $path, $m) === 1) {
            $name = array_search($m[1], $this->zones, true);

            return $name === false
                ? Http::response(['success' => false, 'errors' => [['code' => 1001, 'message' => 'Invalid zone']]], 404)
                : $this->ok(['id' => $m[1], 'name' => $name, 'status' => 'active', 'account' => ['id' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4']]);
        }

        if (preg_match('~^/zones/([^/]+)/dns_records(?:/([^/]+))?$~', $path, $m) !== 1) {
            return Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'No route']]], 404);
        }
        $zoneId = $m[1];
        $recordId = $m[2] ?? null;
        $body = json_decode($request->body(), true);

        if ($request->method() === 'GET') {
            $rows = array_values(array_filter($this->records, static fn (array $record): bool => $record['zone_id'] === $zoneId
                && (! isset($query['name']) || strcasecmp($record['name'], (string) $query['name']) === 0)
                && (! isset($query['type']) || $record['type'] === $query['type'])));

            return $this->ok($rows);
        }

        $this->writes[] = ['method' => $request->method(), 'path' => $path, 'body' => $body];

        if ($request->method() === 'POST') {
            $id = 'r'.$this->nextId++;
            $content = $body['content'] ?? null;
            if ($body['type'] === 'SRV') {
                $content = $body['data']['weight'].' '.$body['data']['port'].' '.$body['data']['target'];
                $body['priority'] = $body['data']['priority'];
            }
            $this->records[$id] = array_filter([
                'id' => $id,
                'zone_id' => $zoneId,
                'type' => $body['type'],
                'name' => $body['name'],
                'content' => $content,
                'priority' => $body['priority'] ?? null,
                'proxied' => $body['proxied'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

            return $this->ok($this->records[$id]);
        }

        if ($request->method() === 'PUT' && $recordId !== null) {
            $this->records[$recordId] = array_merge($this->records[$recordId], [
                'type' => $body['type'],
                'name' => $body['name'],
                'content' => $body['content'] ?? null,
            ]);

            return $this->ok($this->records[$recordId]);
        }

        if ($request->method() === 'DELETE' && $recordId !== null) {
            unset($this->records[$recordId]);

            return $this->ok(['id' => $recordId]);
        }

        return Http::response(['success' => false], 400);
    }

    /**
     * @return list<array{method: string, path: string, body: mixed}>
     */
    public function writesOf(string $method): array
    {
        return array_values(array_filter($this->writes, static fn (array $write): bool => $write['method'] === $method));
    }

    private function ok(mixed $result): PromiseInterface
    {
        return Http::response([
            'success' => true,
            'errors' => [],
            'result' => $result,
            'result_info' => ['page' => 1, 'total_pages' => 1],
        ], 200);
    }
}
