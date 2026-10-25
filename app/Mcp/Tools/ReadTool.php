<?php

namespace App\Mcp\Tools;

use App\Mcp\McpTool;
use App\Models\McpToken;

abstract class ReadTool implements McpTool
{
    public const SITE_PROPERTY = [
        'type' => 'string',
        'description' => 'Site slug, domain or id (list_sites).',
    ];

    public function ability(): string
    {
        return McpToken::ABILITY_READ;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected static function schema(array $properties = [], array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected static function limit(array $arguments, int $default = 25, int $max = 100): int
    {
        $limit = (int) ($arguments['limit'] ?? $default);

        return max(1, min($max, $limit));
    }
}
