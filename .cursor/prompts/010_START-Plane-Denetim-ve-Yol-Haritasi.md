# 010 — START: Plane Denetim, İyileştirme ve Yol Haritası (yapıştır)

> **Kullanıcı:** Aşağıdaki kutuyu yeni bir agent sohbetine (Claude Code / Cursor) **olduğu gibi yapıştır.**
> **Agent:** Sen **ana orkestratörsün**. Analizi tek başına yazma; dalga dalga **paralel subagent** fırlat, çıktıları doğrula, sentezle.
> **Bu iş salt-okunurdur:** uygulama kodu değişmez. Tek çıktı `docs/audit/2026-09-plane-review/` altındaki Markdown dosyalarıdır.

---

## Kullanıcının yapıştıracağı mesaj

```txt
@.cursor/prompts/010_START-Plane-Denetim-ve-Yol-Haritasi.md uygula.

Repo kökü: C:\workspace\deamon\deamon-plane  (dal: o an aktif dal, genelde alpha)
Mod: orchestrator + paralel subagent'lar, dalga dalga.
Çıktı klasörü: docs/audit/2026-09-plane-review/
Kod değişikliği YOK. Dal/worktree açma. Commit yalnızca ben istersem.
Dil: Türkçe (kod, sınıf, route, dosya adları orijinal).
Başla: Dalga 0 (envanter) → Dalga 1 (paralel alan denetimleri) → Dalga 2 (kesişen analizler) → Dalga 3 (sentez + yol haritası) → Dalga 4 (doğrulama).
```

---

## 1. Amaç

Deamon Plane'in (ops paneli, Coolify filosu, tema kataloğu, imzalı site-agent çağrıları, domain/Cloudflare, mail, Deskron) **tamamını** kanıta dayalı analiz edip şu dört soruya yanıt veren, karar vermeye hazır bir doküman seti üretmek:

1. **Mevcut özellikler ne durumda?** Ne var, ne eksik, ne kırılgan, ne yanlış tasarlanmış?
2. **Neyi iyileştirmeliyiz / güncellemeliyiz?** (doğruluk, güvenilirlik, performans, UX, güvenlik, bakım maliyeti)
3. **Plane nasıl daha otonom olur?** Operatörün bugün elle yaptığı ne varsa: tespit → teşhis → güvenli otomatik düzeltme → doğrulama → bildirim döngüsüne nasıl bağlanır?
4. **Hangi yeni özellik / modül değer katar?** Kapsam kısıtları içinde, gerekçeli ve boyutlandırılmış.

Hedef "iyi fikirler listesi" değil: **her bulgu kanıtlı, her öneri etki/efor/risk ile puanlı, her yol haritası maddesi sahipli ve kabul kriterli** olacak.

---

## 2. Zorunlu okuma (orkestratör, ilk tur — subagent'lara da ilgili kısımları ver)

1. `AGENTS.md` — Plane ≠ CMS sınırı, yüklenmeyecekler, sert limitler
2. `docs/README.md`, `docs/architecture.md`, `docs/security.md`, `docs/related-infra.md`
3. `.cursor/prompts/referans/_Scope-Constraints.md` (ADR + yasaklar)
4. `docs/plans/2026-08-13-deamon-plane.md` (SoT plan), `docs/plans/progress-ledger.md`
5. **`docs/plans/2026-09-14-plane-review-and-proposals.md`** — önceki inceleme. Bu çalışma onun **devamıdır**: her önerisinin bugünkü durumu (yapıldı / kısmen / yapılmadı / geçersiz) işaretlenecek, tekrar yazılmayacak.
6. `docs/modules/*.md`, `docs/runbooks/*.md`, `docs/decisions/*.md`
7. `docs/todo_sites_backend.md`, `docs/todo_sites_uiux.md`, `docs/plans/2026-09-10-ui-ux-remediation.md`, `docs/plans/ui-rollout-shared-requests-*.md`
8. `.agents/skills/plane-ui-ux/SKILL.md` (UI/UX alt-ajanı için zorunlu)
9. `git log --oneline -80` — son değişikliklerin yönü (Sites workspace yeniden tasarımı, filtreler, görünüm modları, günlük snapshot, deploy teşhisi, tema dosya özelleştirmeleri vb.)

**Yükleme:** CMS kuralları, CMS skill paketleri, tema Docker hub'ları, Laravel/OWASP `REFERENCE.md` dökümleri (AGENTS.md "Do not load" listesi).

---

## 3. Sert kurallar (her subagent prompt'una AYNEN göm)

```txt
HARD RULES — Plane Audit:
1. SALT-OKUNUR. app/, routes/, resources/, public/, database/, config/, tests/ altında hiçbir dosyayı değiştirme.
   Yalnızca sana atanan docs/audit/2026-09-plane-review/<dosya>.md dosyasını yaz. Başka dosyaya dokunma.
2. Dal açma, worktree açma, commit/push yapma. Uzun süren arka plan süreci bırakma; komutları ön planda çalıştır.
3. Kanıt zorunlu: her bulgu en az bir `yol/dosya.php:satır` referansı (veya komut çıktısı) taşır. Kanıtsız iddia yazma.
   Emin değilsen "Doğrulanmadı" etiketiyle yaz ve nasıl doğrulanacağını söyle.
4. Kapsam: yalnızca deamon-plane. CMS (codron-co/deamon) tarafına düşen işleri "CMS handoff" başlığında ayrı listele, CMS kodu önerme.
   Scope dışı (multi-tenant SaaS, marketplace, müşteri tema ZIP upload, Mailcow — related-infra.md) önerme; önerirsen "Kapsam dışı — bilinçli karar gerekir" diye işaretle.
5. Sır/secret yazma: .env değerleri, token, parola, agent secret, IP'ler dokümana girmez. Gerekirse "<gizli>" yaz.
6. Canlı sistemlere (Coolify, Cloudflare, GitHub, Hostinger, müşteri siteleri) istek atma. Http::fake'li testler ve kod okuma yeterli.
7. Mevcut test paketi çalıştırılabilir (php artisan test --compact, node --test tests/js/ops-contracts.test.js) — yalnızca okumak/ölçmek için.
8. Dil Türkçe; kod tanımlayıcıları orijinal. Kısa, taranabilir, tablolarla. Dolgu cümle yazma.
9. Önceki inceleme (docs/plans/2026-09-14-plane-review-and-proposals.md) maddelerini tekrar keşfetme: durumunu güncelle ve referans ver.
```

---

## 4. Çıktı yapısı

Klasör: `docs/audit/2026-09-plane-review/`

| Dosya | Sahip (dalga) | İçerik |
|---|---|---|
| `README.md` | Orkestratör (D3) | Klasör indeksi, okuma sırası, yöntem, puanlama anahtarı, tarih + HEAD sha |
| `00-yonetici-ozeti.md` | Orkestratör (D3) | 1 sayfa: en kritik 10 bulgu, en değerli 10 öneri, otonomi hedefi, önerilen ilk 30/60/90 gün |
| `01-envanter.md` | S0 (D0) | Sayılarla sistem haritası: route, controller, servis, job, komut, schedule, model/tablo, migration, entegrasyon, audit action, test sayıları; modül → dosya haritası; önceki raporla fark tablosu |
| `02-mimari-ve-kod-sagligi.md` | S1 (D1) | Katmanlar, sınırlar, tekrar eden kod, büyük sınıflar (satır sayısı top 20), controller şişmesi, N+1/sorgu riskleri, test boşlukları, teknik borç envanteri |
| `03-sites-ve-yasam-dongusu.md` | S2 (D1) | Provision, attach/import, kanal geçişi, arşiv/purge, yayın durumu, liste/filtre/görünüm, site detay, toplu işlemler |
| `04-coolify-deploy-ve-teshis.md` | S3 (D1) | Coolify client, rate guard/retry, envanter senkron, webhook'lar, deploy kuyruğu, pin/follow HEAD/auto-deploy, deploy teşhisi + otomatik tek güvenli düzeltme, compose pack migrasyonu |
| `05-agent-saglik-ve-izleme.md` | S4 (D1) | Site agent sözleşmesi, health check planlama, app-health teşhis/fix, live probe, stale mantığı, günlük snapshot/trend, alarm/bildirim eksikleri |
| `06-temalar-ve-rollout.md` | S5 (D1) | Git bağlantıları, katalog senkron, kurulum/güncelleme/rollback, auto-update webhook, sürüm kapıları, dosya özelleştirme koruması, storefront kontrolü |
| `07-domain-cloudflare-dns.md` | S6 (D1) | Domain kayıt defteri, bind/rebind, Cloudflare hesap/zone/DNS şablonu, SSL/önizleme host, DNS onayı akışı |
| `08-mail-ve-bildirimler.md` | S7 (D1) | Hostinger mail sunucuları, site bağlama, mailbox istekleri, platform maili (SMTP, katalog, override, unsubscribe), Deskron entegrasyonu |
| `09-ui-ux-i18n-erisilebilirlik.md` | S8 (D1) | Tüm ekranlar: shell, Fleet, Sites, site detay, Coolify, Cloudflare, Domains, Themes, Mail, Settings, Activity, Account. Skill kontrol listesine göre ekran ekran puan; i18n sızıntıları; a11y; responsive; tutarsız primitive'ler |
| `10-guvenlik-yetki-denetim.md` | S9 (D2) | Rol/policy matrisi (Super Admin/Operator/Viewer × route), imzalı agent çağrıları, webhook doğrulama, secret saklama/rotasyon, audit kapsamı, CSRF/XSS/SSRF/açık yönlendirme, rate limit, bağımlılıklar |
| `11-operasyon-altyapi-gozlemlenebilirlik.md` | S10 (D2) | Plane'in kendi deploy'u (Compose, supervisord, queue, scheduler), job güvenilirliği (retry/timeout/unique/overlap), yedekleme, loglama, metrik, hata izleme, runbook boşlukları, felaket senaryoları |
| `12-performans-ve-olcek.md` | S1 veya S10 (D2) | 50 → 500 site senaryosu: sorgu sayısı, tam filo taramaları (ör. `reportedDeamonVersions`), cache, kuyruk kapasitesi, Coolify API bütçesi, sayfa ağırlığı |
| `13-otonomi-yol-haritasi.md` | S11 (D2) | Otonomi merdiveni (aşağıda), mevcut her operasyonel akışın bugünkü seviyesi ve hedefi, güvenli otomasyon için kontrol noktaları (guardrail, bütçe, kill switch, onay kapısı, audit), "otopilot" politikası taslağı |
| `14-yeni-ozellik-ve-modul-onerileri.md` | S12 (D2) | Aday modüller/özellikler: problem → kullanıcı → çözüm taslağı → bağımlılık → boyut → risk → kapsam uygunluğu. Reddedilen fikirler de gerekçesiyle |
| `15-onceki-inceleme-durum-takibi.md` | S0 (D0) | 2026-09-14 raporundaki her maddenin durumu: Yapıldı (commit) / Kısmen / Yapılmadı / Geçersiz + not |
| `16-yol-haritasi-ve-backlog.md` | Orkestratör (D3) | Tüm önerilerin tek tabloda birleşik, puanlı, sıralı backlog'u; 3 faz (Şimdi / Sonra / Belki); her madde için sahip modül, kabul kriteri, bağımlılık, CMS handoff gereksinimi |
| `17-cms-handoff.md` | Orkestratör (D3) | Plane önerilerinin CMS (`codron-co/deamon`) tarafında gerektirdiği sözleşme/endpoint/sürüm değişiklikleri — yalnızca istek listesi |
| `18-acik-sorular-ve-kararlar.md` | Orkestratör (D3) | Kullanıcının vermesi gereken kararlar (seçenekler + önerilen default + etkisi) |

Dosya adları sabittir. Bir alt-ajan yalnızca kendi dosyasını yazar; orkestratör yalnızca kendi dosyalarını ve sentezi yazar.

---

## 5. Her alan raporunun şablonu (02–14 için zorunlu)

```md
# <No> — <Başlık>

**Tarih:** YYYY-MM-DD · **HEAD:** <kısa sha> · **Hazırlayan:** <alt-ajan rolü>
**Kapsam:** <hangi modüller / dosya kökleri> · **Kapsam dışı:** <...>

## Özet
3–6 madde: en önemli bulgular ve en değerli öneriler.

## 1. Mevcut durum
- Akış(lar): kısa adım listesi veya mermaid diyagramı (gerçek sınıf/route adlarıyla)
- Ana dosyalar tablosu: dosya | sorumluluk | satır | test dosyası
- Test kapsamı: hangi davranışlar testli, hangileri değil

## 2. Bulgular
| ID | Tür | Önem | Bulgu | Kanıt | Etki |
|----|-----|------|-------|-------|------|
| S3-B01 | Hata / Risk / Borç / UX / Perf / Güvenlik / Eksik | Kritik/Yüksek/Orta/Düşük | ... | `app/...php:123` | ... |

## 3. İyileştirme ve güncelleme önerileri
| ID | Öneri | Çözdüğü bulgu | Etki (1–5) | Efor (S/M/L/XL) | Risk (1–5) | Öncelik puanı | Kabul kriteri |
|----|-------|---------------|-----------|-----------------|-----------|---------------|---------------|

## 4. Otonomi fırsatları
| Akış | Bugünkü seviye (L0–L5) | Hedef | Tetik | Güvenli otomatik adım | Guardrail | Bildirim |

## 5. Yeni özellik fikirleri (bu alana özgü)
Kısa liste; ayrıntı 14-...md'de birleşecek.

## 6. CMS handoff
Bu alandaki önerilerin CMS tarafında gerektirdikleri (yoksa "Yok").

## 7. Açık sorular
Karar gerektiren noktalar + önerilen default.
```

**Öncelik puanı** = `Etki × 2 − Risk + (S=3, M=2, L=1, XL=0)`. Aynı formülü tüm dosyalarda kullan; README'de açıkla.

**Önem tanımları:** Kritik = veri kaybı / güvenlik açığı / canlı sitelerin kesintisi; Yüksek = sık operatör hatası veya sessiz yanlış durum; Orta = verim/UX kaybı; Düşük = kozmetik/bakım.

---

## 6. Otonomi merdiveni (13 numaralı dosya ve tüm alan raporları bu ölçeği kullanır)

| Seviye | Tanım | Örnek |
|---|---|---|
| L0 | Görünmez — Plane durumu bilmiyor | Operatör sorunu müşteriden duyuyor |
| L1 | Görünür — veri toplanıyor, ekranda | Sağlık rozeti, son deploy |
| L2 | Teşhis — neden bulunuyor, operatöre öneri | Deploy teşhisi, app-health fix önerisi |
| L3 | Tek tık düzeltme — güvenli aksiyon hazır, insan tetikliyor | "Fix App issues", redeploy |
| L4 | Koşullu otomatik — guardrail'li otomatik düzeltme, sonradan bildirim | Throttle'a takılan deploy'u otomatik yeniden deneme |
| L5 | Kapalı döngü — tespit → düzelt → doğrula → gerekirse geri al → raporla | Tema güncellemesi → storefront kontrolü → otomatik rollback |

Her otomatik öneri için zorunlu alanlar: **tetik**, **ön koşul**, **eylem**, **doğrulama**, **geri alma**, **bütçe/limit** (ör. saatte N işlem, Coolify API bütçesi), **kill switch** (Settings'te kapatılabilir mi), **audit kaydı**, **bildirim kanalı**.

---

## 7. Dalgalar ve subagent planı

### Dalga 0 — Envanter ve temel çizgi (1 alt-ajan: S0, sıralı)

- `01-envanter.md`: ölçümleri komutla üret (ör. `php artisan route:list --json | ...`, `ls app/Services -R`, `grep -c` ile audit action'ları, `routes/console.php` schedule'ları, `database/migrations` sayısı, `php artisan test --compact` özeti). Önceki raporun sayılarıyla fark tablosu.
- `15-onceki-inceleme-durum-takibi.md`: 2026-09-14 raporundaki her öneri için `git log -S`/`grep` ile kanıtlı durum.
- Modül → dosya haritası (Dalga 1 alt-ajanları için sahiplik referansı).

**Kapı:** Orkestratör envanteri okur, Dalga 1 alt-ajan prompt'larına ilgili dosya köklerini ve önceki rapor maddelerini ekler.

### Dalga 1 — Alan denetimleri (8 alt-ajan PARALEL: S1–S8)

| Alt-ajan | Dosya | Okuyacağı kökler (başlangıç, eksikse genişlet) |
|---|---|---|
| S1 Mimari & kod sağlığı | 02 | tümü; `app/Http/Controllers/Ops`, `app/Services`, `app/Support`, `tests/` |
| S2 Sites | 03 | `SiteController`, `SiteDetailController`, `SiteCoolifyOpsController`, `SitePublishStatusController`, `SiteListPreferencesController`, `app/Services/Sites`, `app/Support/Lists`, `resources/views/ops/sites`, `docs/modules/ops-sites.md` |
| S3 Coolify & deploy | 04 | `app/Services/Coolify`, `CoolifyConnectionController`, `CoolifyInventoryController`, `DeploymentDiagnosisController`, `DeploymentShowController`, webhooks, `Deployment` modeli, ilgili job'lar, `docs/modules/coolify-*.md`, `docs/runbooks/deploy-failure-triage.md`, `coolify-rate-limit.md` |
| S4 Agent & sağlık | 05 | `app/Services/Agent`, `app/Services/Fleet`, `SiteAppHealthController`, health job'ları, `SiteListSummary`, `FleetDailySnapshot`, `docs/modules/agent-client.md`, ADR-10 |
| S5 Temalar | 06 | `app/Services/Themes`, `app/Services/GitHub`, `ThemeController`, `SiteThemeController`, `ThemeGitConnectionController`, `DeamonGitConnectionController`, `docs/modules/theme-*.md`, `github-webhooks.md`, `docs/runbooks/theme-rollout.md` |
| S6 Domain & Cloudflare | 07 | `app/Services/Cloudflare`, `app/Services/Domains`, `DomainController`, `SiteDomainController`, `SiteCloudflareController`, `CloudflareOpsController`, `docs/modules/cloudflare-client.md` |
| S7 Mail & Deskron | 08 | `app/Services/Mail`, `app/Services/Hostinger`, `app/Services/Deskron`, `MailServerOpsController`, `PlatformMailSettingsController`, `PlatformMailUnsubscribeController`, `DeskronSettingsController`, `docs/modules/mail-servers.md`, `platform-mail.md`, `docs/plans/mail-hostinger-plane-report.md` |
| S8 UI/UX | 09 | `resources/views/**`, `public/css/*.css`, `public/js/*.js`, `lang/tr`, `lang/en`, skill dosyası, `docs/examples/ui/sites-redesign.html` |

S8 için ek: Her ekranı skill'in "Acceptance checklist" maddelerine göre ✅/⚠️/❌ puanla; i18n sızıntısını `grep` ile bul (Blade içinde `__(` dışı sabit Türkçe/İngilizce metin); `ops.css` + `ops-ui.css` + `plane-refresh.css` katmanlaşmasının teknik borcunu (ölü seçiciler, override zincirleri) ölç. Tarayıcı erişimi varsa yerel önizleme ile 1280/1440/1920/768/390 genişliklerinde ekran görüntüsü alıp gözlemleri yaz; yoksa "görsel doğrulama yapılmadı" diye belirt. **Parola girme, giriş yapma** — gerekiyorsa sunucu tarafında oturum açılmış kullanıcıyla render edilmiş statik HTML kullan.

**Kapı (orkestratör):** Her dosyayı şablona uygunluk, kanıt yoğunluğu (her bulguda `dosya:satır`), çakışan/çelişen bulgular açısından incele. Eksikse aynı alt-ajana düzeltme mesajı gönder (yeni ajan açma). Rastgele 5 kanıt referansını kendin aç ve doğrula; yanlışsa raporu reddet.

### Dalga 2 — Kesişen analizler (4 alt-ajan PARALEL: S9–S12; Dalga 1 çıktılarını girdi olarak alır)

| Alt-ajan | Dosya | Not |
|---|---|---|
| S9 Güvenlik & yetki | 10 | Policy matrisi tablo halinde (route × rol × beklenen × gerçek). Webhook imza/replay, SSRF (Coolify/Cloudflare URL'leri, favicon, live probe), açık yönlendirme (`back()` / `redirect` kullanımları), mass assignment, secret şifreleme, log'a sızan veri. Bulguları Kritik/Yüksek ayrı bölümde, istismar adımı YAZMADAN sınıf düzeyinde anlat. |
| S10 Operasyon & altyapı | 11 (+12 perf) | `docker-compose.coolify.yml`, `docker/`, supervisord, queue worker sayısı/timeout, `routes/console.php`, job `tries/backoff/timeout/unique`, yedek, log rotasyonu, hata izleme; Plane çökerse ne olur senaryoları. Perf için 500 site senaryosunu sorgu ve API çağrı sayısıyla hesapla. |
| S11 Otonomi | 13 | Dalga 1'deki tüm "Otonomi fırsatları" tablolarını birleştir, merdivende konumla, bağımlılık sırasına diz. "Otopilot politikası" taslağı: hangi aksiyon hangi koşulda insan onayı olmadan çalışır, günlük bütçe, sessiz saatler, kanal bazlı (alpha agresif, main temkinli) farklar, kill switch, bildirim (mail/Deskron/webhook). |
| S12 Yeni özellik & modül | 14 | Dalga 1 fikirlerini + kendi taramanı birleştir. Aday örnekleri (kanıtla doğrula, körü körüne kopyalama): uyarı/bildirim merkezi ve on-call özeti, SLA/uptime raporu, sertifika/alan adı bitiş takibi, yedek/geri yükleme orkestrasyonu (site DB/storage), staging→prod promosyon akışı, sürüm dalgası (canary) rollout, maliyet/kapasite paneli (sunucu doluluğu), değişiklik takvimi/bakım penceresi, operatör görev kuyruğu/playbook motoru, müşteri bazlı raporlama, API/CLI erişimi, çoklu kullanıcı bildirim tercihleri. Her aday için: problem, kanıt (hangi bulgu/eksik), MVP kapsamı, boyut, risk, kapsam uygunluğu. |

**Kapı:** S11 ve S12 çıktılarında her öneri bir Dalga 1/2 bulgusuna veya envanter kanıtına bağlanmalı. Bağsız öneri → geri gönder.

### Dalga 3 — Sentez (orkestratör, sıralı)

1. `16-yol-haritasi-ve-backlog.md`: 02–14'teki tüm öneri satırlarını tek tabloya birleştir; tekrarları birleştir (hangi ID'lerin birleştiğini yaz); öncelik puanına göre sırala; üç faz:
   - **Şimdi (0–30 gün):** Kritik/Yüksek bulgu kapatanlar + yüksek puanlı S/M işler
   - **Sonra (30–90 gün):** Otonomi L3→L4 geçişleri, orta boy modüller
   - **Belki / Araştır:** XL işler, kapsam kararı gerektirenler
   Her madde: ID, başlık, kaynak ID'ler, modül, puan, efor, bağımlılık, kabul kriteri, CMS handoff (evet/hayır), önerilen spec yolu (`docs/superpowers/specs/<tarih>-<slug>.md`).
2. `17-cms-handoff.md`, `18-acik-sorular-ve-kararlar.md`.
3. `00-yonetici-ozeti.md` ve `README.md` (en son).

### Dalga 4 — Doğrulama (1 alt-ajan: S13 "kırmızı takım" + orkestratör)

- S13 tüm klasörü okur; şunları raporlar (orkestratöre mesaj, dosya yazmaz):
  - kanıtı olmayan veya yanlış satıra işaret eden bulgular (en az 15 referansı rastgele açarak)
  - birbiriyle çelişen öneriler
  - kapsam dışına taşan öneriler
  - puanlaması tutarsız maddeler
  - önceki rapordan tekrar edilmiş ama durumu işaretlenmemiş maddeler
- Orkestratör düzeltmeleri ilgili dosyalara işler ve `README.md` sonuna "Doğrulama notu" ekler (kaç referans kontrol edildi, kaç düzeltme).

---

## 8. Alt-ajan prompt kalıbı (orkestratör her alt-ajana bunu doldurarak gönderir)

```txt
Rol: <S2 — Sites ve yaşam döngüsü denetçisi>
Repo: C:\workspace\deamon\deamon-plane (dal: mevcut, genelde alpha). HEAD: <sha>

<HARD RULES bloğunu AYNEN yapıştır>

Görev: docs/audit/2026-09-plane-review/<03-sites-ve-yasam-dongusu.md> dosyasını, bu prompttaki
"Her alan raporunun şablonu" bölümüne birebir uyarak yaz.

Önce oku: AGENTS.md; docs/architecture.md; ilgili docs/modules ve runbooks; 01-envanter.md;
15-onceki-inceleme-durum-takibi.md (bu alanla ilgili satırlar); aşağıdaki kökler: <liste>.

Yapılacaklar:
1. Akışları uçtan uca izle (route → controller → request → service → job → model → view → test).
2. Her akış için: hata yolları, yarış durumları, idempotency, yetki kontrolü, audit kaydı, kullanıcıya geri bildirim, test kapsamı.
3. Bulguları kanıtla yaz (dosya:satır). Emin olmadığını "Doğrulanmadı" diye işaretle.
4. İyileştirme önerilerini puanla (Öncelik puanı formülü). Her önerinin ölçülebilir kabul kriteri olsun.
5. Otonomi fırsatlarını L0–L5 ölçeğiyle ve zorunlu alanlarla (tetik, ön koşul, eylem, doğrulama, geri alma, bütçe, kill switch, audit, bildirim) yaz.
6. Bu alana özgü yeni özellik fikirlerini kısaca listele.
7. CMS handoff ve açık soruları ayır.

Ölçü: Rapor 250–600 satır arası; tablo ağırlıklı. En az 12 bulgu, en az 10 öneri hedefle —
ama sayı için dolgu yapma; alan küçükse az yaz ve nedenini söyle.

Bitince bana şunu döndür: dosya yolu, bulgu sayısı (önem kırılımı), öneri sayısı, en kritik 3 bulgu (tek satır),
doğrulayamadığın noktalar.
```

---

## 9. Orkestratörün kalite kapıları (her dalga sonunda)

- [ ] Her dosya şablona uyuyor; başlıkta tarih + HEAD var.
- [ ] Bulguların ≥ %90'ında `dosya:satır` kanıtı var; rastgele 5 tanesini açıp doğruladım.
- [ ] ID'ler benzersiz (`S<n>-B<nn>` bulgu, `S<n>-O<nn>` öneri).
- [ ] Puanlar formüle uygun.
- [ ] Kapsam dışı öneriler işaretli; CMS işleri handoff'ta.
- [ ] Secret/IP/token yok (`grep -iE "token|secret|password|[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}"` ile tara, gerçek değer çıkarsa sil).
- [ ] Hiçbir kod dosyası değişmedi: `git status --short` yalnızca `docs/audit/2026-09-plane-review/` gösteriyor.

---

## 10. Bitiş raporu (orkestratör → kullanıcı, Türkçe, kısa)

- Oluşturulan dosyalar listesi (link).
- Önem kırılımıyla toplam bulgu sayısı; Kritik/Yüksek olanların tek satırlık listesi.
- "Şimdi" fazındaki ilk 10 madde.
- Otonomi: bugünkü ortalama seviye → 90 gün hedefi, ilk 3 otomasyon.
- En değerli 5 yeni modül/özellik adayı.
- Kullanıcıdan beklenen kararlar (18 numaralı dosyadan).
- Doğrulanamayan noktalar.
- Commit yapılmadı; istersen `docs(audit): ...` mesajıyla yalnızca bu klasörü commit'leyebilirim.
