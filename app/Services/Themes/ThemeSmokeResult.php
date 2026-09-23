<?php

namespace App\Services\Themes;

final class ThemeSmokeResult
{
    /**
     * @param  array<string, int>  $checked  path => HTTP status (0 = unreachable)
     * @param  array<string, int>  $failures  path => 5xx status
     */
    public function __construct(
        public readonly bool $skipped,
        public readonly array $checked = [],
        public readonly array $failures = [],
    ) {}

    public static function skipped(): self
    {
        return new self(skipped: true);
    }

    public function failed(): bool
    {
        return ! $this->skipped && $this->failures !== [];
    }

    /**
     * "/timeline → 500, / → 502" for the installation error and the audit row.
     */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->failures as $path => $status) {
            $parts[] = $path.' → '.$status;
        }

        return implode(', ', $parts);
    }
}
