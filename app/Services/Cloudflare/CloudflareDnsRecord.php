<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class CloudflareDnsRecord
{
    /**
     * @var list<string>
     */
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    public function __construct(
        public string $type,
        public string $relativeName,
        public string $content,
        public ?int $priority = null,
        public bool $proxied = false,
    ) {}

    public static function relative(string $name, string $zoneName): string
    {
        $host = strtolower(rtrim(trim($name), '.'));
        $zone = strtolower(rtrim(trim($zoneName), '.'));

        if ($host === '' || $host === '@' || $host === $zone) {
            return '@';
        }

        $suffix = '.'.$zone;
        if (str_ends_with($host, $suffix)) {
            $relative = substr($host, 0, -strlen($suffix));

            return $relative !== '' ? $relative : '@';
        }

        return $name;
    }

    public static function host(string $name, string $zoneName): string
    {
        $zone = strtolower(rtrim(trim($zoneName), '.'));
        $relative = self::relative($name, $zone);

        if ($relative === '@') {
            return $zone;
        }

        return $relative.'.'.$zone;
    }

    /**
     * @return array{type: string, name: string, content: string, ttl: int, proxied: bool, priority?: int}
     */
    public static function fromRequest(Request $request, ?string $zoneName = null): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:2048'],
            'ttl' => ['nullable', 'integer', 'min:1', 'max:86400'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $type = strtoupper(trim((string) $validated['type']));
        $name = trim((string) $validated['name']);
        if ($zoneName !== null) {
            $name = self::relative($name, $zoneName);
        }

        $record = [
            'type' => $type,
            'name' => $name,
            'content' => trim((string) $validated['content']),
            'ttl' => (int) ($validated['ttl'] ?? 1) ?: 1,
            'proxied' => false,
        ];

        if ($type === 'MX') {
            if (! isset($validated['priority']) || $validated['priority'] === null) {
                throw ValidationException::withMessages([
                    'priority' => __('cloudflare.dns.priority_required'),
                ]);
            }

            $record['priority'] = (int) $validated['priority'];
        } elseif (isset($validated['priority']) && $validated['priority'] !== null) {
            $record['priority'] = (int) $validated['priority'];
        }

        return $record;
    }

    public function fqdn(string $apex): string
    {
        if ($this->relativeName === '@') {
            return $apex;
        }

        return $this->relativeName.'.'.$apex;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(string $apex): array
    {
        $payload = [
            'type' => $this->type,
            'name' => $this->fqdn($apex),
            'content' => $this->content,
            'ttl' => 1,
        ];

        if (in_array($this->type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = $this->proxied;
        }

        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }
}
