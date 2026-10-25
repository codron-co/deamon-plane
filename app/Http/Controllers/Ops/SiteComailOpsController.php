<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Jobs\ApplyComailPackageJob;
use App\Models\MailPackage;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDns;
use App\Services\Comail\ComailDomainProvisioner;
use App\Services\Comail\ComailException;
use App\Services\Comail\ComailLimits;
use App\Services\Comail\ComailSiteConfigurer;
use App\Services\Comail\MailcowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Site detail → Infrastructure → CoMail card: package, domains, DNS, MX switch
 * and the CMS re-push. Every mutation is audited (never a password or key).
 */
class SiteComailOpsController extends Controller
{
    public function package(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'mail_package_id' => ['nullable', 'string', Rule::exists('mail_packages', 'id')],
        ]);

        $before = $site->mail_package_id;
        $site->mail_package_id = filled($validated['mail_package_id'] ?? null) ? (string) $validated['mail_package_id'] : null;
        $site->save();

        $this->audit($site, $request, 'comail.package_changed', [
            'from' => $before,
            'to' => $site->mail_package_id,
            'package' => MailPackage::forSite($site)->slug,
        ]);

        if ($site->comailDomains()->exists()) {
            ApplyComailPackageJob::dispatch((string) $site->id);
        }

        return $this->back($site)->with('status', __('comail.site.flash.package_saved'));
    }

    public function addDomain(Request $request, Site $site, ComailDomainProvisioner $provisioner, ComailDns $dns, ComailLimits $limits, ComailSiteConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! MailServer::comail() instanceof MailServer) {
            return $this->back($site)->with('error', __('comail.site.errors.no_server'));
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', Rule::in(ComailDomainProvisioner::eligibleHosts($site))],
        ]);
        $domain = strtolower((string) $validated['domain']);

        try {
            if ($site->comailDomains()->where('domain', $domain)->doesntExist()) {
                $limits->assertCanAddDomain($site);
            }
            $primary = $site->comailDomains()->where('is_primary', true)->doesntExist()
                && ComailDomainProvisioner::primaryHost($site) === $domain;
            $row = $provisioner->ensure($site, $domain, $primary);
            $dns->applyIfManaged($row);
        } catch (ComailException|MailcowException $exception) {
            return $this->back($site)->with('error', __('comail.site.errors.failed', ['message' => $this->message($exception)]));
        }

        $this->audit($site, $request, 'comail.domain_added', ['domain' => $domain]);
        $this->queueConfigure($site, $configurer);

        return $this->back($site)->with('status', __('comail.site.flash.domain_added', ['domain' => $domain]));
    }

    public function applyDns(Request $request, Site $site, SiteComailDomain $comailDomain, ComailDns $dns): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->assertOwned($site, $comailDomain);

        try {
            $dns->apply($comailDomain);
        } catch (ComailException $exception) {
            return $this->back($site)->with('error', $exception->getMessage());
        }

        $this->audit($site, $request, 'comail.dns_applied', ['domain' => $comailDomain->domain]);

        return $this->back($site)->with('status', __('comail.site.flash.dns_applied', ['domain' => $comailDomain->domain]));
    }

    public function checkDns(Request $request, Site $site, SiteComailDomain $comailDomain, ComailDns $dns): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->assertOwned($site, $comailDomain);

        $result = $dns->check($comailDomain);
        $ok = count(array_filter($result['records'], static fn (array $record): bool => ($record['status'] ?? null) === 'ok'));

        return $this->back($site)->with('status', __('comail.site.flash.dns_checked', [
            'domain' => $comailDomain->domain,
            'ok' => $ok,
            'total' => count($result['records']),
        ]));
    }

    public function activate(Request $request, Site $site, SiteComailDomain $comailDomain, ComailDns $dns): RedirectResponse
    {
        $this->authorize('update', $site);
        $this->assertOwned($site, $comailDomain);

        try {
            $dns->activate($comailDomain);
        } catch (ComailException $exception) {
            return $this->back($site)->with('error', $exception->getMessage());
        }

        $this->audit($site, $request, 'comail.mx_activated', ['domain' => $comailDomain->domain]);

        return $this->back($site)->with('status', __('comail.site.flash.activated', ['domain' => $comailDomain->domain]));
    }

    public function configure(Request $request, Site $site, ComailSiteConfigurer $configurer): RedirectResponse
    {
        $this->authorize('update', $site);

        if (! $site->hasAgentSecret()) {
            return $this->back($site)->with('error', __('mail.flash.needs_secret'));
        }

        $configurer->queue($site);
        $this->audit($site, $request, 'comail.configure_requeued', []);

        return $this->back($site)->with('status', __('mail.flash.configure_queued'));
    }

    private function queueConfigure(Site $site, ComailSiteConfigurer $configurer): void
    {
        if ($site->hasAgentSecret()) {
            $configurer->queue($site);
        }
    }

    private function assertOwned(Site $site, SiteComailDomain $row): void
    {
        abort_unless((string) $row->site_id === (string) $site->id, 404);
    }

    private function message(ComailException|MailcowException $exception): string
    {
        return $exception instanceof MailcowException ? $exception->turkishMessage() : $exception->getMessage();
    }

    private function back(Site $site): RedirectResponse
    {
        return redirect()->to(route('ops.sites.show', $site).'#site-comail');
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(Site $site, Request $request, string $action, array $after): void
    {
        $site->auditLogs()->create([
            'actor_user_id' => $request->user()?->id,
            'action' => $action,
            'after' => $after,
            'ip' => $request->ip(),
        ]);
    }
}
