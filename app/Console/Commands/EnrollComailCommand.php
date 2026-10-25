<?php

namespace App\Console\Commands;

use App\Jobs\EnsureComailDomainJob;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Comail\ComailDomainProvisioner;
use Illuminate\Console\Command;

/**
 * Var olan siteleri CoMail'e kaydeder (yalnız ana alan adı). Yeni siteler
 * oluşturma ve provision sonunda kendiliğinden kaydolur; bu komut canlı siteler
 * içindir ve kendiliğinden çalışmaz. Mevcut MX'e dokunulmaz: MX yalnız alan
 * adında hiç MX yoksa yazılır, gerisi panelden "MX'i CoMail'e geçir" ile.
 * İşler aralıklı kuyruğa girer: mailcow ve Cloudflare'e aynı anda yüklenilmez.
 */
class EnrollComailCommand extends Command
{
    protected $signature = 'ops:comail-enroll
        {--site=* : Site slug\'ı (birden fazla verilebilir)}
        {--all : Bütün siteler}
        {--dry-run : Yalnız listeyi göster, hiçbir şey yapma}';

    protected $description = 'Enroll existing sites\' primary domains in CoMail (mailcow domain, DKIM, DNS without touching existing MX, CMS push)';

    public function handle(): int
    {
        $slugs = array_values(array_filter((array) $this->option('site')));
        if ($slugs === [] && ! $this->option('all')) {
            $this->error('--site=<slug> ya da --all verin.');

            return self::FAILURE;
        }

        if (! MailServer::comail() instanceof MailServer) {
            $this->error('CoMail sunucusu yok: /mail-servers altında etkin bir CoMail (mailcow) sunucusu ekleyin.');

            return self::FAILURE;
        }

        $query = Site::query()->orderBy('name');
        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        }

        $dryRun = (bool) $this->option('dry-run');
        $delay = 0;
        $count = 0;

        foreach ($query->get() as $site) {
            $domain = ComailDomainProvisioner::primaryHost($site);
            if ($domain === null) {
                $this->warn($site->slug.': ana alan adı postaya uygun değil (geçici/önizleme), atlandı.');

                continue;
            }

            $row = SiteComailDomain::query()->where('domain', $domain)->first();
            if ($row instanceof SiteComailDomain && (string) $row->site_id !== (string) $site->id) {
                $this->warn($site->slug.': '.$domain.' başka bir sitede kayıtlı, atlandı.');

                continue;
            }
            if ($row instanceof SiteComailDomain && $row->isActive()) {
                $this->line($site->slug.': '.$domain.' zaten CoMail’de.');

                continue;
            }

            $count++;
            if ($dryRun) {
                $this->line($site->slug.': '.$domain.' kaydedilecek.');

                continue;
            }

            EnsureComailDomainJob::dispatch((string) $site->id, $domain, true)->delay(now()->addSeconds($delay));
            $delay += 15;
            $this->info($site->slug.': '.$domain.' sıraya alındı.');
        }

        $this->info($count.' site'.($dryRun ? ' kaydedilecek (deneme, hiçbir şey yapılmadı).' : ' sıraya alındı.'));

        return self::SUCCESS;
    }
}
