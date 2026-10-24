<?php

namespace App\Console\Commands;

use App\Enums\SiteStatus;
use App\Jobs\SyncSiteTurnstileJob;
use App\Models\Site;
use App\Services\Turnstile\SiteTurnstileSync;
use Illuminate\Console\Command;

/**
 * Var olan siteleri bot korumasına (Turnstile) kaydeder. Yeni siteler provision
 * sonunda kendiliğinden kaydolur; bu komut canlı siteler içindir. İşler aralıklı
 * kuyruğa girer: Cloudflare ve CMS'e aynı anda yüklenilmez.
 */
class EnrollTurnstileCommand extends Command
{
    protected $signature = 'ops:turnstile-enroll
        {sites?* : Site slug\'ları}
        {--all : Bütün aktif siteler}
        {--dry-run : Yalnız listeyi göster}';

    protected $description = 'Enroll existing sites in Cloudflare Turnstile bot protection and push the keys';

    public function handle(SiteTurnstileSync $sync): int
    {
        $slugs = array_values(array_filter((array) $this->argument('sites')));
        if ($slugs === [] && ! $this->option('all')) {
            $this->error('Site slug\'ı verin ya da --all kullanın.');

            return self::FAILURE;
        }

        $query = Site::query()->where('status', SiteStatus::Active)->orderBy('name');
        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        }

        $delay = 0;
        $count = 0;
        foreach ($query->get() as $site) {
            if (! $site->hasAgentSecret() || $sync->accountFor($site) === null) {
                $this->warn($site->slug.': agent anahtarı ya da Cloudflare hesabı yok, atlandı.');

                continue;
            }

            if ($site->turnstile()->exists()) {
                $this->line($site->slug.': zaten kayıtlı.');

                continue;
            }

            $count++;
            if ($this->option('dry-run')) {
                $this->line($site->slug.': kaydedilecek.');

                continue;
            }

            $sync->enroll($site);
            SyncSiteTurnstileJob::dispatch((string) $site->id)->delay(now()->addSeconds($delay));
            $delay += 20;
            $this->info($site->slug.': kaydedildi, eşitleme sırada.');
        }

        $this->info($count.' site'.($this->option('dry-run') ? ' kaydedilecek.' : ' kaydedildi.'));

        return self::SUCCESS;
    }
}
