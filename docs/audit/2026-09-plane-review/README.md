# Deamon Plane denetimi — 2026-09

> **ADR-12 notu (2026-09-24, denetim sonrası karar):** Site env'i 6 önyükleme anahtarına iner; yeni zorunlu env anahtarı eklenmez, yeni ayarlar agent ile gelir; `APP_ENV` her zaman `production` ve kanal geçişi yalnızca dalı değiştirir. Bu belgedeki env/kanal önerileri bu kararla birlikte okunmalı — [ADR-12](../../decisions/adr-12-site-config-from-plane.md).

**Tarih:** 2026-09-24 · **HEAD:** `8484d37` (`alpha`) · **Önceki inceleme:** [2026-09-14](../../plans/2026-09-14-plane-review-and-proposals.md) (HEAD `242ae15`)
**Başlatan prompt:** [`.cursor/prompts/010_START-Plane-Denetim-ve-Yol-Haritasi.md`](../../../.cursor/prompts/010_START-Plane-Denetim-ve-Yol-Haritasi.md)

Kanıta dayalı, salt-okunur denetim. Uygulama kodu değişmedi; canlı sistemlere (Coolify, Cloudflare, GitHub, Hostinger, müşteri siteleri) istek atılmadı.

## Okuma sırası

1. [00 — Yönetici özeti](00-yonetici-ozeti.md): bir sayfa
2. [16 — Yol haritası ve backlog](16-yol-haritasi-ve-backlog.md): 68 madde, üç faz
3. [18 — Açık sorular ve kararlar](18-acik-sorular-ve-kararlar.md): önce §1 (Hafta 1)
4. [13 — Otonomi yol haritası](13-otonomi-yol-haritasi.md): otopilot politikası taslağı §8
5. İlgili alan raporu (aşağıdaki indeks)

## İndeks

| Dosya | İçerik | Hazırlayan | Dalga |
|-------|--------|-----------|-------|
| [00-yonetici-ozeti.md](00-yonetici-ozeti.md) | En kritik 10 bulgu, en değerli 10 öneri, otonomi hedefi, 30/60/90 | Orkestratör | 3 |
| [01-envanter.md](01-envanter.md) | Sayılarla sistem haritası, modül → dosya haritası, önceki raporla fark | S0 | 0 |
| [02-mimari-ve-kod-sagligi.md](02-mimari-ve-kod-sagligi.md) | Katmanlar, büyük sınıflar, tekrar, sessiz `catch`, test boşlukları | S1 | 1 |
| [03-sites-ve-yasam-dongusu.md](03-sites-ve-yasam-dongusu.md) | Provision, attach, kanal, arşiv/purge, liste, toplu işlemler | S2 | 1 |
| [04-coolify-deploy-ve-teshis.md](04-coolify-deploy-ve-teshis.md) | Coolify client, webhook, deploy kuyruğu, teşhis + otomatik düzeltme, env katalog | S3 | 1 |
| [05-agent-saglik-ve-izleme.md](05-agent-saglik-ve-izleme.md) | Agent sözleşmesi, health, app-health, snapshot, alarm, secret | S4 | 1 |
| [06-temalar-ve-rollout.md](06-temalar-ve-rollout.md) | GitHub App, katalog, kurulum/güncelleme/rollback, auto-update | S5 | 1 |
| [07-domain-cloudflare-dns.md](07-domain-cloudflare-dns.md) | Domain kayıt defteri, bind, Cloudflare zone/DNS şablonu | S6 | 1 |
| [08-mail-ve-bildirimler.md](08-mail-ve-bildirimler.md) | Hostinger, mail proxy, platform mail, Deskron, bildirim altyapısı | S7 | 1 |
| [09-ui-ux-i18n-erisilebilirlik.md](09-ui-ux-i18n-erisilebilirlik.md) | 27 ekran × 9 kriter, i18n sızıntısı, a11y, CSS/JS borcu | S8 | 1 |
| [10-guvenlik-yetki-denetim.md](10-guvenlik-yetki-denetim.md) | Rol/policy matrisi (144 yazma route'u), imza/webhook, secret, audit | S9 | 2 |
| [11-operasyon-altyapi-gozlemlenebilirlik.md](11-operasyon-altyapi-gozlemlenebilirlik.md) | Plane deploy'u, kuyruk, scheduler, takılı durumlar, yedek, felaket senaryoları | S10 | 2 |
| [12-performans-ve-olcek.md](12-performans-ve-olcek.md) | 50 → 500 site hesabı, sorgu sayıları, darboğazlar | S10 | 2 |
| [13-otonomi-yol-haritasi.md](13-otonomi-yol-haritasi.md) | Birleşik otonomi envanteri, güvenlik tabanı T01–T19, otopilot politikası | S11 | 2 |
| [14-yeni-ozellik-ve-modul-onerileri.md](14-yeni-ozellik-ve-modul-onerileri.md) | 24 aday modül, reddedilen fikirler | S12 | 2 |
| [15-onceki-inceleme-durum-takibi.md](15-onceki-inceleme-durum-takibi.md) | 2026-09-14 raporunun her maddesinin durumu | S0 | 0 |
| [16-yol-haritasi-ve-backlog.md](16-yol-haritasi-ve-backlog.md) | Birleşik, puanlı, fazlı backlog | Orkestratör | 3 |
| [17-cms-handoff.md](17-cms-handoff.md) | CMS'ten istenen sözleşme/payload/endpoint değişiklikleri | Orkestratör | 3 |
| [18-acik-sorular-ve-kararlar.md](18-acik-sorular-ve-kararlar.md) | Kullanıcı kararları (seçenek + default + etki) | Orkestratör | 3 |

## Yöntem

- **Dalga 0:** Envanter sayıları komutla üretildi (`route:list`, `find`, `grep`, tam test paketi: 1080 geçti / 0 başarısız, 572 sn; node 22/22). Önceki raporun 65 maddesi `git log -S` ve `grep` ile işaretlendi.
- **Dalga 1:** 8 alan denetçisi paralel çalıştı. Her akış route → controller → request → service → job → model → view → test zinciriyle izlendi. Hedefli testler (`--filter`) ve repo dışına yazılan kanıt probları kullanıldı.
- **Dalga 2:** Güvenlik, operasyon/performans, otonomi ve yeni özellik analizleri Dalga 1 çıktılarını girdi aldı. Önceki bulgular yeniden keşfedilmedi; ID ile referans verildi.
- **Dalga 3:** Orkestratör 258 öneri satırını 68 backlog maddesine birleştirdi (birleştirme kaydı [16 §7](16-yol-haritasi-ve-backlog.md)). CMS istekleri ve kararlar ayrıldı.
- **Dalga 4:** Kırmızı takım doğrulaması (aşağıdaki not).

### Kalite kapıları

- Her rapor şablona uyuyor; başlıkta tarih ve HEAD var.
- **Satır referansı kapısı:** İlk teslimlerde birden fazla dosyanın tek `cat -n` ile açılması yüzünden satır numaraları kaymıştı. Tüm alt-ajanlar referanslarını betikle yeniden doğruladı (toplam 90+ düzeltme). Sonra orkestratör 1.235 referansı aralık betiğiyle yeniden taradı: sınır dışı 0. Betiğin çözemediği kısaltılmış yollar (`vendor/` çekirdek dosyaları, tarih öneksiz migration adları, `.cursor/` dosyaları) elle kontrol edildi.
- **Elle doğrulanan kanıtlar (orkestratör):** S1-B09, S1-B12, S2-B04, S2-B10, S3-B01, S4-B03, S5-B01, S6-B01, S7-B01, S8-B03, S9-B01, S9-B03, S10-B01, S10-B13, S12-B17. Hepsi doğru.
- **Puan kapısı:** Tüm öneri tabloları formülle betikle karşılaştırıldı; hata 0.
- **ID kapısı:** Bulgu `S<n>-B<nn>`, öneri `S<n>-O<nn>`; S10'un performans dosyası çakışmasın diye `S10-PB`/`S10-PO` kullanır; S12 aday modülleri `S12-M<nn>`.
- **Secret taraması:** Raporlarda IP, token, parola değeri yok. Koddaki sabit origin IP'si ve compose'daki sabit DB parolası bilinçli olarak yazılmadı.

## Puanlama anahtarı

**Öncelik puanı** = `Etki × 2 − Risk + efor bonusu` (S = 3, M = 2, L = 1, XL = 0). Etki ve Risk 1–5 arasıdır; puan yüksekse önce yapılır.

**Önem:**

| Önem | Tanım |
|------|-------|
| Kritik | Veri kaybı, güvenlik açığı veya canlı sitelerin kesintisi |
| Yüksek | Sık operatör hatası veya sessiz yanlış durum |
| Orta | Verim veya UX kaybı |
| Düşük | Kozmetik veya bakım |

**Otonomi merdiveni:** L0 Görünmez · L1 Görünür · L2 Teşhis · L3 Tek tık düzeltme · L4 Koşullu otomatik · L5 Kapalı döngü (tanımlar [13](13-otonomi-yol-haritasi.md)).

## Sınırlar

- **Görsel doğrulama yapılmadı.** UI raporu kod okumasına dayanır; tarayıcıyla giriş yapılmadı.
- **Canlıya bakılmadı.** "Doğrulanmadı" etiketli maddeler ve [18 §5](18-acik-sorular-ve-kararlar.md)'teki sunucu soruları canlı ortam bilgisi gerektirir.
- Bulgular HEAD `8484d37` içindir. Çalışma ağacındaki commitlenmemiş değişiklikler (`.cursor/README.md`) kapsam dışıdır.

## Doğrulama notu (Dalga 4)

Kırmızı takım (S13) klasörün tamamını okudu. Hiçbir dosya yazmadı; bulguları orkestratöre bildirdi, düzeltmeleri orkestratör uyguladı.

| Kontrol | Sonuç |
|---------|-------|
| Rastgele kanıt (orkestratörün baktıkları hariç) | 30 referans: ✅ 27 · ⚠️ 3 · ❌ 0. Örneklem dışında 1 yanlış iddia bulundu (S6-B04) |
| Orkestratörün elle doğruladığı kanıt | 15 (yukarıda) |
| Betikle aralık kontrolü | 1.235 referans, sınır dışı 0 |
| Puan formülü | 258 kaynak satır + 67 backlog satırı, hata 0 |
| Göreli linkler (hedef konum varsayımıyla) | Kırık 0 |
| Secret/IP taraması | Gerçek değer 0 |
| Kapsam taşması | İşaretlenmemiş ciddi taşma yok; 3 küçük ifade düzeltildi |

**Uygulanan düzeltmeler: 7 sonuç değiştiren + 20 kozmetik.**

Sonuç değiştirenler:

1. **P06 / S6-O01:** "Eksikse ekle" kuralı çift SPF/DMARC ve ek MX üretebiliyordu. Kural şöyle değişti: ilgili tür zone'da varsa ne PUT ne POST yapılır, fark dikkat kartına düşer.
2. **S6-B04:** `bind_domains` teşhis tarafından otomatik uygulanmıyor, hiçbir kuralın `auto` değeri değil. Otomatik PATCH-only yol yalnız Sync auto-rebind.
3. **00:** "Tekil bulgu 279" etiketi "Bulgu satırı 279 (tekilleştirilmemiş)" oldu. S9'un yeniden puanlama notu eklendi.
4. **16 puanları:** Birleştirme kuralı netleşti (Etki/Risk = kaynakların en yükseği, Efor = en büyük dilim). P02 12 → 10, P15 → 9, P18 → 9, P19 → 8, P32 → 9, P33 → 9, P40 → 6, P41 → 6.
5. **Hafta 1 tanımı:** 6 Kritik + 2 Yüksek madde. 13'ün ilk hafta şartı için P17, P09'un doğru satır dilimi ve P13'ün salt-okunur dilimi Hafta 1'e çekildi.
6. **P04 CMS bağımlılığı:** "Evet" → "Opsiyonel (C01)". Hafta 1 yalnız Plane'de yapılabilir.
7. **Önceki A3/1.3-c** (import sonrası agent secret) backlog'da yoktu; P51'e eklendi.

Kozmetik olanlar:

- Satır takası (S1-B11) ve S3-B27 ifadesi.
- S12-B05 ve S12-B09 metinleri; S10-PB01'deki supervisord yolu.
- "XSS temiz" istisnası; K10 listesi; T06/T09/T13/T19 eşlemeleri.
- P36 ve P59 başlıkları; ADR numarası.
- Önceki madde önekleri (Öneri-B8 vb.); 11'deki durum ayrımı.
- Kaçışsız `|` içeren 5 tablo satırı ve 04'teki kopuk tablo satırı; prob yolu notu.

**Bilinen açık:**

- `probe-s5/` klasörü (S5'in geçici kanıt testleri) repo'ya taşınmaz.
- Bu README'nin başındaki prompt linki (`.cursor/prompts/010_...md`) git'te henüz izlenmiyor. Commit'e eklenmezse uzakta kırık kalır.
