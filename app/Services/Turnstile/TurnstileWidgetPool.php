<?php

namespace App\Services\Turnstile;

use App\Models\CloudflareSetting;
use App\Models\SiteTurnstile;
use App\Models\TurnstileWidget;
use App\Services\Cloudflare\CloudflareApiException;
use App\Services\Cloudflare\CloudflareClient;
use Illuminate\Support\Facades\Cache;

/**
 * Turnstile widget havuzu. Ücretsiz planda hesap başına 20 widget ve widget
 * başına 10 alan adı var; ~30 siteye ayrı widget yetmez, tek widget da 10 alan
 * adında dolar. Bu yüzden her widget modu için, aynı moddaki siteler alan
 * adlarını 10'a kadar bir widget'ta paylaşır; dolan widget'ın yanına yenisi açılır.
 *
 * Her site üç modun (managed, non-interactive, invisible) hepsinden bir widget'ta
 * durur: mod değişikliği Cloudflare'e gitmeden, yalnız CMS'te anahtar seçerek olur.
 * Paylaşılan widget'ta CMS siteverify hostname'ini kendi host'uyla karşılaştırır.
 */
final class TurnstileWidgetPool
{
    private const LOCK_SECONDS = 120;

    /**
     * Sitenin alan adlarını her moddan bir widget'a yerleştirir.
     *
     * @param  list<string>  $hosts
     * @return array<string, string> widget modu => turnstile_widgets.id
     *
     * @throws CloudflareApiException
     * @throws TurnstilePoolException
     */
    public function assign(SiteTurnstile $record, CloudflareSetting $account, array $hosts): array
    {
        if ($hosts === []) {
            throw new TurnstilePoolException('no_hostnames');
        }

        if (count($hosts) > TurnstileWidget::MAX_DOMAINS) {
            throw new TurnstilePoolException('too_many_hostnames');
        }

        return Cache::lock('turnstile-pool:'.$account->id, self::LOCK_SECONDS)->block(60, function () use ($record, $account, $hosts): array {
            $client = CloudflareClient::fromSettings($account);
            $previousHosts = $record->hostnameList();
            $current = $record->widgets();

            // Başka hesaba taşındıysa eski hesaptaki yerini boşalt.
            if ((int) $record->cloudflare_setting_id !== (int) $account->id) {
                $this->releaseWidgets($record);
                $current = [];
                $previousHosts = [];
            }

            $assigned = [];
            foreach (SiteTurnstile::WIDGET_MODES as $mode) {
                $assigned[$mode] = $this->place($client, $account, $mode, $hosts, $previousHosts, $current[$mode] ?? null);
            }

            return $assigned;
        });
    }

    /**
     * Sitenin alan adlarını bütün widget'lardan çıkarır; boşalan widget silinir.
     *
     * @throws CloudflareApiException
     */
    public function release(SiteTurnstile $record): void
    {
        $account = $record->cloudflareAccount;
        if (! $account instanceof CloudflareSetting) {
            return;
        }

        Cache::lock('turnstile-pool:'.$account->id, self::LOCK_SECONDS)->block(60, function () use ($record): void {
            $this->releaseWidgets($record);
        });
    }

    private function releaseWidgets(SiteTurnstile $record): void
    {
        $hosts = $record->hostnameList();
        foreach ($record->widgets() as $widget) {
            $account = $widget->cloudflareAccount;
            if ($account instanceof CloudflareSetting) {
                $this->removeHosts(CloudflareClient::fromSettings($account), $account, $widget, $hosts);
            }
        }
    }

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $previousHosts
     */
    private function place(CloudflareClient $client, CloudflareSetting $account, string $mode, array $hosts, array $previousHosts, ?TurnstileWidget $current): string
    {
        if ($current instanceof TurnstileWidget) {
            $others = array_values(array_diff($current->domainList(), $previousHosts, $hosts));
            $domains = array_values(array_unique(array_merge($others, $hosts)));

            if (count($domains) <= TurnstileWidget::MAX_DOMAINS) {
                $this->writeDomains($client, $account, $current, $domains);

                return (string) $current->id;
            }

            // Yeni alan adları sığmıyor: eski yerini boşalt, başka widget'a geç.
            $this->removeHosts($client, $account, $current, $previousHosts);
        }

        $widget = $this->widgetWithRoom($account, $mode, count($hosts));
        if ($widget instanceof TurnstileWidget) {
            $domains = array_values(array_unique(array_merge($widget->domainList(), $hosts)));
            $this->writeDomains($client, $account, $widget, $domains);

            return (string) $widget->id;
        }

        return (string) $this->createWidget($client, $account, $mode, $hosts)->id;
    }

    private function widgetWithRoom(CloudflareSetting $account, string $mode, int $needed): ?TurnstileWidget
    {
        return TurnstileWidget::query()
            ->where('cloudflare_setting_id', $account->id)
            ->where('mode', $mode)
            ->orderBy('created_at')
            ->get()
            ->first(fn (TurnstileWidget $widget): bool => count($widget->domainList()) + $needed <= TurnstileWidget::MAX_DOMAINS);
    }

    /**
     * @param  list<string>  $hosts
     */
    private function createWidget(CloudflareClient $client, CloudflareSetting $account, string $mode, array $hosts): TurnstileWidget
    {
        $count = TurnstileWidget::query()->where('cloudflare_setting_id', $account->id)->count();
        if ($count >= TurnstileWidget::MAX_WIDGETS_PER_ACCOUNT) {
            throw new TurnstilePoolException('widget_limit');
        }

        $sequence = TurnstileWidget::query()
            ->where('cloudflare_setting_id', $account->id)
            ->where('mode', $mode)
            ->count() + 1;
        $name = sprintf('Deamon · %s · %02d', $mode, $sequence);

        $result = $client->createTurnstileWidget((string) $account->account_id, $name, $hosts, $mode);
        $sitekey = is_string($result['sitekey'] ?? null) ? $result['sitekey'] : '';
        $secret = is_string($result['secret'] ?? null) ? $result['secret'] : '';
        if ($sitekey === '' || $secret === '') {
            throw new TurnstilePoolException('invalid_cloudflare_response');
        }

        return TurnstileWidget::query()->create([
            'cloudflare_setting_id' => $account->id,
            'mode' => $mode,
            'name' => $name,
            'sitekey' => $sitekey,
            'secret' => $secret,
            'domains' => $hosts,
        ]);
    }

    /**
     * @param  list<string>  $hosts
     */
    private function removeHosts(CloudflareClient $client, CloudflareSetting $account, TurnstileWidget $widget, array $hosts): void
    {
        $domains = array_values(array_diff($widget->domainList(), $hosts));
        if ($domains === $widget->domainList()) {
            return;
        }

        if ($domains === []) {
            try {
                $client->deleteTurnstileWidget((string) $account->account_id, (string) $widget->sitekey);
            } catch (CloudflareApiException $exception) {
                if ($exception->status !== 404) {
                    throw $exception;
                }
            }
            $widget->delete();

            return;
        }

        $this->writeDomains($client, $account, $widget, $domains);
    }

    /**
     * @param  list<string>  $domains
     */
    private function writeDomains(CloudflareClient $client, CloudflareSetting $account, TurnstileWidget $widget, array $domains): void
    {
        $sorted = $domains;
        sort($sorted);
        $existing = $widget->domainList();
        sort($existing);
        if ($sorted === $existing) {
            return;
        }

        $client->updateTurnstileWidget((string) $account->account_id, (string) $widget->sitekey, (string) $widget->name, $domains, (string) $widget->mode);
        $widget->forceFill(['domains' => array_values($domains)])->save();
    }
}
