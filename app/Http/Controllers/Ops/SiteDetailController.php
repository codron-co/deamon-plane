<?php

namespace App\Http\Controllers\Ops;

use App\Enums\OpsRole;
use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Ops\Concerns\LoadsSiteOpsContext;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\Theme;
use App\Services\Agent\SiteHealthEvaluator;
use App\Services\Cloudflare\CloudflareAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SiteDetailController extends Controller
{
    use LoadsSiteOpsContext;

    public function __invoke(Request $request, Site $site, SiteHealthEvaluator $agentHealth): View
    {
        $this->authorize('view', $site);

        $site->load([
            'primaryDomainRecord',
            'domains',
            'themeInstallations.theme',
            'coolifyConnection',
            'mailServer',
            'cloudflareAccount',
            'activeThemeInstallation.theme',
        ]);

        $user = $request->user();

        return view('ops.sites.show', [
            'site' => $site,
            'canEdit' => $user?->can('update', $site) ?? false,
            'canDelete' => $user?->can('delete', $site) ?? false,
            'canProvision' => ($user?->can('provision', $site) ?? false)
                && $site->canBeProvisioned(),
            'canSwitchChannel' => ($user?->can('switchChannel', $site) ?? false)
                && $site->canSwitchChannel(),
            'canForceChannel' => $user?->hasRole(OpsRole::SuperAdmin->value) ?? false,
            'canCheckHealth' => $user?->can('checkHealth', $site) ?? false,
            'canInjectAgentSecret' => ($user?->can('update', $site) ?? false)
                && filled($site->coolify_app_uuid),
            'canSyncCoolify' => ($user?->can('update', $site) ?? false)
                && filled($site->coolify_app_uuid),
            'canLiveSync' => ($user?->can('update', $site) ?? false)
                && filled($site->primary_domain),
            'canActivate' => ($user?->can('update', $site) ?? false)
                && $site->canBeActivated(),
            'canDeactivate' => ($user?->can('update', $site) ?? false)
                && $site->canBeDeactivated(),
            'canForceDelete' => $user?->can('forceDelete', $site) ?? false,
            'agentHealth' => $agentHealth,
            'channelSwitchTargets' => $this->channelSwitchTargets($site),
            'channelSwitchInProgress' => $site->status === SiteStatus::Deploying,
            'deployments' => $site->deployments()
                ->with('requestedBy')
                ->latest('id')
                ->limit(25)
                ->get(),
            'coolifyAppUrl' => $site->coolifyUiUrl(),
            'themeInstallations' => $site->themeInstallations,
            'assignableThemes' => $this->assignableThemes($site),
            'canAssignTheme' => $user?->can('assign', Theme::class) ?? false,
            'mailServers' => MailServer::query()->where('is_enabled', true)->orderBy('name')->get(),
            'cloudflareAccounts' => CloudflareAccounts::enabled(),
        ]);
    }
}
