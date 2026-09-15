<?php

namespace App\Rules;

use App\Models\Site;
use App\Models\SiteDomain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An archived (soft-deleted) site still owns its slug and hosts: `sites.slug`,
 * `sites.primary_domain` and `site_domains.domain` are unique at the database level,
 * and the fleet importer treats a trashed site as occupying its host. The other
 * rules skip trashed rows, so without this a reused value passed validation and
 * the INSERT failed with a 500. Name the archived site so the operator can restore
 * or purge it.
 */
final class NotHeldByArchivedSite implements ValidationRule
{
    private function __construct(
        private readonly string $column,
        private readonly ?string $exceptSiteId,
    ) {}

    public static function slug(?string $exceptSiteId = null): self
    {
        return new self('slug', $exceptSiteId);
    }

    public static function host(?string $exceptSiteId = null): self
    {
        return new self('host', $exceptSiteId);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return;
        }

        $owner = $this->archivedOwner($value);
        if (! $owner instanceof Site) {
            return;
        }

        $fail(__('sites.form.archived_owner', [
            'value' => $value,
            'site' => $owner->name,
        ]));
    }

    private function archivedOwner(string $value): ?Site
    {
        $sites = Site::onlyTrashed()
            ->when($this->exceptSiteId !== null, fn ($query) => $query->whereKeyNot($this->exceptSiteId));

        if ($this->column === 'slug') {
            return $sites->where('slug', $value)->first();
        }

        $byPrimary = (clone $sites)->where('primary_domain', $value)->first();
        if ($byPrimary instanceof Site) {
            return $byPrimary;
        }

        $row = SiteDomain::query()
            ->where('domain', $value)
            ->whereIn('site_id', $sites->select('id'))
            ->first();

        return $row instanceof SiteDomain
            ? Site::withTrashed()->find($row->site_id)
            : null;
    }
}
