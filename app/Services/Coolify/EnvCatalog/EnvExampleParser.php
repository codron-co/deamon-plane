<?php

namespace App\Services\Coolify\EnvCatalog;

use App\Enums\CoolifyEnvKind;

/**
 * Parses the CMS `.env.production.example` (single source of truth per git branch)
 * into Coolify env catalog rows.
 *
 * Rules:
 * - `KEY=value` lines are rows; comment / blank / `# KEY=` (disabled) lines are not.
 * - Kind comes from the value: `{{generated}}` → generated, `{{site.*}}` / `{{plane.*}}` → site,
 *   `{{coolify*}}` → skip, empty → required, anything else → static.
 * - `#@secret` directly above a key marks the row as secret.
 * - Plain `#` comment lines directly above a key (no blank line between) are its description.
 */
final class EnvExampleParser
{
    public const SECRET_DIRECTIVE = '#@secret';

    /**
     * @return list<ParsedEnvRow>
     */
    public function parse(string $contents): array
    {
        $rows = [];
        $seen = [];
        $description = [];
        $secret = false;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                $description = [];
                $secret = false;

                continue;
            }

            if (str_starts_with($line, '#')) {
                if (strcasecmp($line, self::SECRET_DIRECTIVE) === 0) {
                    $secret = true;

                    continue;
                }

                if (str_starts_with($line, '#@')) {
                    // Unknown directive: ignore, keep the comment block.
                    continue;
                }

                $text = trim(substr($line, 1));
                if ($text === '' || preg_match('/^[A-Z][A-Z0-9_]*=/', $text) === 1) {
                    // Separator or a disabled `# KEY=value` line: not a description.
                    $description = [];

                    continue;
                }

                $description[] = $text;

                continue;
            }

            if (preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=(.*)$/', $line, $match) !== 1) {
                $description = [];
                $secret = false;

                continue;
            }

            $key = $match[1];
            $value = $this->unquote(trim($match[2]));

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $rows[] = new ParsedEnvRow(
                    key: $key,
                    kind: $this->kindFor($value),
                    value: $value === '' ? null : $value,
                    isSecret: $secret,
                    description: $description === [] ? null : implode(' ', $description),
                );
            }

            $description = [];
            $secret = false;
        }

        return $rows;
    }

    public function kindFor(string $value): CoolifyEnvKind
    {
        $value = trim($value);

        if ($value === '') {
            return CoolifyEnvKind::Required;
        }

        if (strcasecmp($value, '{{generated}}') === 0) {
            return CoolifyEnvKind::Generated;
        }

        if (preg_match('/^\{\{\s*coolify(\.[^}]*)?\s*\}\}$/i', $value) === 1) {
            return CoolifyEnvKind::Skip;
        }

        if (preg_match('/^\{\{\s*(site|plane)\.[a-z_]+\s*\}\}$/i', $value) === 1) {
            return CoolifyEnvKind::Site;
        }

        return CoolifyEnvKind::Static;
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
