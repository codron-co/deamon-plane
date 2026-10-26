# Tek Redis'e geçiş planı (Deamon siteleri)

Durum: **uygulandı** (2026-10-25'te başladı, 2026-10-26'da 35 site taşındı). Hedef: 200 site (1 ay). Paylaşılan MySQL geçişi (`docs/modules/database-servers.md`, `tools/ops/README.md`) ile aynı yöntem ve aynı derslerle.

## İlerleme

| Faz | Durum |
|---|---|
| 0 Envanter | ✅ Bütün siteler yalnız DB 0/1; ilk 2.000 anahtarda süresiz anahtar yok; en büyük anahtar ~300 B; Plane'de phpredis 6.3.0 |
| 1 Sunucu | ✅ `deamon-redis` (7.4.11): `redis` adı çözülmüyor, şifresiz komut `NOAUTH`, yeniden başlatmada veri kalıyor, izleme ve yedek cron'u çalışıyor |
| 2 Plane | ✅ canlıda (alpha `1f458a1`, migration batch 45). `Deamon Redis` kayıtlı ve varsayılan (`ops:redis-server`) |
| 3 CMS | ✅ codron-co/deamon #50, 1.2.82: beta = main = alpha = `338f4d36` (2026-10-26 02:16 TR); 36 sitenin hepsi bu imajda |
| 4 Prova | ✅ deamon-test anahtarları TTL ve önek değişimiyle test DB'sine kopyalandı, sayılar tuttu. ACL: başka önek (başka DB'de de) `NOPERM`; `FLUSHALL`/`FLUSHDB`/`KEYS`/`CONFIG` `NOPERM`; kendi anahtarlarıyla Lua çalışıyor, Lua içinden başka anahtar `NOPERM`. `ops:site-redis prepare test` → DB 2/3, `site_test` ACL dosyasına yazıldı |
| 5 deamon-test | ✅ 2026-10-26 01:14'te paylaşılan Redis'te. 24 saat gözlem yerine (kullanıcı kararı) Chrome + terminal testi: site/admin 78 sayfa 200, oturum ve CSRF Redis'te, admin girişi, site sağlığı taraması (kuyruk), iletişim formu, yorum aç/kapa, zamanlayıcı; 15 dk MONITOR: 12 bin komut, hepsi izinli, hepsi `site_test:` öneki; failed job, ACL reddi, hata kaydı 0. İleri 9 sn, `--back` 6 sn, yeniden ileri 8 sn; site ve admin 200; kuyruk işi işlendi, `queue:work`/`schedule:work` ayakta; Coolify'da 7 REDIS_* kayıtlı (deploy'da kalır). Provada bulunan: `--back` sonrası paylaşılan çiftte eski kopya kalıyordu ve yeniden ileri kopya reddediliyordu; ileri taşıma artık `prepare` sonrası `flush` yapıyor. `watch.sh` ACL reddini de bildiriyor |
| 6 Bütün siteler | ✅ 2026-10-26 02:18–06:28 TR, `fleet-db-rollout.sh` (MOVE_SCRIPT=move-site-redis.sh, CPU < %80): 22 site deploy, 34 site taşındı (site başına 8–20 sn bakım). 35 site paylaşılan Redis'te, yalnız nova yerelde (Nova oturumunun isteği). İlk denemede deamon-zorlu geri alındı: açılışta paylaşılan MySQL'den `1615 Prepared statement needs to be re-prepared` (table_definition_cache 2000 < 7.181 tablo) → 10000 yapıldı, MySQL bellek sınırı 4→6 GB; ardından taşındı. Son durum: 36/36 healthy, 200, paylaşılan Redis 11 MB, evicted 0, ACL reddi 0. Kalan: eski site Redis container'ları geri dönüş için duruyor; compose'tan kaldırma (MySQL faz 3 ile birlikte) ayrı onayla |

## Neden

Bugün her site yığınında ayrı bir `redis:7-alpine` var. Site başına ölçüm (36 site, 2026-10-25):

| | Site başına | 200 sitede |
|---|---|---|
| RAM | 3 MB | 0,6 GB (önemsiz) |
| Boşta CPU | %0,42 | **~0,85 çekirdek**, Hostinger CPU tabanına doğrudan eklenir |
| Container + 2 dk'lık sağlık kontrolü | 1 | 200 container, saatte 6.000 `docker exec` |

Tek Redis bunları yaklaşık %1 CPU'ya ve tek container'a indirir. RAM kazancı küçük; asıl kazanç CPU tabanı ve container sayısı.

## Bugünkü kullanım (ölçüldü)

- CMS bağlantıları: `default` = DB 0 (kuyruk, oturum, kilit), `cache` = DB 1. Önek `REDIS_PREFIX` yoksa `deamon-database-` (APP_NAME'den), önbellek iç öneki `deamon-cache-`. **Bütün sitelerde aynı önek** (birleştirmenin asıl riski).
- Site başına 60–1.900 anahtar, 1,4–3,2 MB. **Anahtarların hepsi süreli (TTL)**: oturum, önbellek, kilit. Ölçüm anında kuyrukta bekleyen iş yok; `queue:work --sleep=1` işleri hemen tüketiyor.
- Kodda `FLUSHDB`/`FLUSHALL`/`KEYS` yok. Redis'e doğrudan erişim yalnız `ping` (ReadinessCheck, ControlPlaneHealthReporter). Açılışta `cache:clear` çalışmıyor; yalnız `config:cache`.
- `config/database.php` `REDIS_USERNAME`/`REDIS_PASSWORD`'ü zaten okuyor (ACL hazır).
- **Engel:** `docker/entrypoint.sh` → `wait_for_redis` şifresiz `ping` atıyor. Şifreli sunucuda `NOAUTH` döner ve site 60 sn sonra açılmadan çıkar. Faz 3'te düzeltilir.

## Tasarım

- **Sunucu:** `/opt/deamon-redis` (Coolify kaynağı değil; Coolify deploy'da yığını yeniden yaratır), imaj `redis:7.4-alpine` (sabit sürüm), dış ağ `deamon-db`.
  - Container, servis ve ağdaki takma ad: **`deamon-redis`. Asla `redis` değil.** Site yığınlarında `redis` kendi servisleri; MySQL'de `mysql` adı 5 siteyi düşürdü.
  - Sınırlar: 0,5 CPU (Redis kuralı), 1,5 GB RAM, `restart: unless-stopped`.
  - `maxmemory 1gb`, `maxmemory-policy volatile-lru` (yalnız süreli anahtar silinir; kuyruk işleri süresiz, silinmez).
  - `appendonly yes` + `appendfsync everysec`, `save 900 1`, `databases 1024`, `maxclients 10000`, `timeout 300`.
  - Port dışarı açık değil; `default` kullanıcı kapalı, yönetici kullanıcı `plane_admin`.
- **Site başına üç kat ayrım** (biri yanlış yapılandırılsa bile diğerleri korur):
  1. kendi önek: `REDIS_PREFIX=site_<slug>:`;
  2. kendi ACL kullanıcısı `site_<slug>` + rastgele parola; kural `~site_<slug>:* &site_<slug>:* +@all -@dangerous -flushdb -flushall -keys`; başka sitenin anahtarı okunamaz ya da silinemez;
  3. kendi DB numaraları: `REDIS_DB=N`, `REDIS_CACHE_DB=N+1`. Plane benzersiz dağıtır; 1.024 DB ile 511 site.
- **Plane:** MySQL'deki gibi `database_servers` benzeri bir Redis sunucu kaydı ve site alanları (`redis_server_id`, `redis_db`, `redis_username`, şifreli `redis_password`). `REDIS_HOST/PORT/USERNAME/PASSWORD/DB/CACHE_DB/PREFIX` bootstrap anahtarı (env eşitleme silmez, paylaşılan sitelerde her eşitlemede yazar). Komut `ops:site-redis status|prepare|switch|local|flush <site>`. `flush` yönetici olarak yalnız o sitenin önekini SCAN ile siler; `cache:clear`'ın yerini tutar, çünkü site kullanıcısında `FLUSHDB` kapalı.

## Riskler ve önlemler

| Risk | Önlem |
|---|---|
| Siteler aynı öneki kullanıyor; anahtarlar karışır | Üç kat ayrım (önek + ACL deseni + DB no) |
| Bir sitenin önbellek temizliği herkesi siler | Site kullanıcısında `FLUSHDB`/`FLUSHALL` kapalı; temizlik `ops:site-redis flush` ile yalnız o önek |
| Bellek dolunca kuyruk işi silinir | `volatile-lru`: yalnız süreli anahtarlar silinir. Bellek %70 ve `evicted_keys > 0` WhatsApp uyarısı. Beklenen kullanım ≤0,6 GB / 1 GB |
| Tek iş parçacığı, bir site hepsini yavaşlatır | `KEYS`, `MONITOR`, `DEBUG`, `CONFIG` site kullanıcılarına kapalı (`-@dangerous`); kodda tarama yok; `slowlog` izlenir |
| Redis düşerse bütün siteler düşer | `unless-stopped`, sağlık kontrolü, AOF; deamon-watch ve Plane uyarısı. MySQL ile aynı risk sınıfı. İkinci sunucuda replika 200 site sonrası ayrı iş |
| Ağda `redis` adı çakışır | Ad `deamon-redis`. Faz 1 çıkış koşulu: `deamon-db` ağında `nslookup redis` başarısız olmalı |
| Açılış betiği şifresiz `ping` atar, site açılmaz | Faz 3'te kullanıcı adı/parolayla `ping`; geçiş betiği yeni compose ve betik yoksa siteye dokunmaz |
| Varsayılan değişip mevcut veriye erişim kaybolur | Faz 3 varsayılanları bugünkü değerlerle birebir (`redis`, 6379, DB 0/1, önek `deamon-database-`, şifre boş). deamon-test'te davranışın değişmediği doğrulanır |
| Geçişte kuyruk işi ya da oturum kaybolur | Bakım modu (işçi ve zamanlayıcı durur), rezerve iş bitene kadar bekleme, bütün anahtarlar TTL korunarak kopyalanır, DB başına anahtar sayısı karşılaştırılır |
| `withoutOverlapping` kilidi kaybolur | Kilitler de TTL'leriyle kopyalanır; bakımda zamanlayıcı çalışmaz |
| Coolify'a özgü tuzaklar | MySQL'de bulunan çözümler aynen: `.env` satır sonu ve yazım doğrulaması, restart yerine yalnız app'i build'siz yeniden yaratma, bakım penceresine deploy düşerse geçmeme |
| Sır sızıntısı | Parolalar Plane'de şifreli ve Coolify env'de; hata mesajları `SecretRedactor`'dan geçer; ACL dosyası 600 |
| CPU kısıtlaması | Geçiş build yapmaz; CPU < %80 kuralı; dağıtımlar tek tur |

## Fazlar

Her faz bir öncekinin çıkış koşulu sağlanmadan başlamaz. Onay noktaları ✋ ile işaretli.

### Faz 0 — Envanter ve kararlar (salt okuma)

- Ölçüm yapıldı (yukarıda). Eksikler: her siteyi bir kez daha tara (DB 2+ kullanılıyor mu, süresiz anahtar var mı, en büyük anahtar), Laravel kuyruk Lua betiklerinin ACL anahtar deseniyle çalıştığını yerel Redis'te doğrula.
- Çıkış: anahtar türleri ve boyutları belli; tasarım bu belgeye işlendi.

### Faz 1 — Paylaşılan Redis sunucusu ✋

- `/opt/deamon-redis/docker-compose.yml`, `redis.conf`, `users.acl` (yönetici + kapalı default), `.env` (600). Plane reposunda `tools/ops/deamon-redis/` altında sürüm kontrolüyle.
- Gece yedeği: `BGSAVE` sonrası RDB kopyası, 7 gün (`/opt/deamon-redis/backups`).
- Sitelere dokunulmaz.
- Çıkış:
  - container healthy;
  - `deamon-db` ağında `deamon-redis` çözülüyor, `redis` çözülmüyor;
  - şifresiz bağlantı reddediliyor;
  - yeniden başlatmada AOF'tan veri geri geliyor.

### Faz 2 — Plane: kayıt, ACL ve geçiş araçları

- Model, migration, env eşitleme (bootstrap anahtarları), `ops:site-redis`, Veritabanları sayfasına Redis bölümü (bellek, istemci, `evicted_keys`, site başına anahtar sayısı), WhatsApp uyarısı.
- `tools/ops/move-site-redis.sh`: `move-site-db.sh`'in yapısı ve dersleriyle. Kopyalama site app container'ında PHP/phpredis ile yapılır; container hem kendi `redis`'ine hem `deamon-redis`'e ulaşır. Her anahtar için `DUMP` + `PTTL` → yeni önekle `RESTORE`.
- Testler: ACL kuralı üretimi, DB numarası benzersizliği, env eşitleme, komutlar. Yerel Redis'e karşı uçtan uca deneme.
- Çıkış:
  - Plane testleri yeşil;
  - yerel Redis'te ACL izolasyonu kanıtlandı: başka önek okunamıyor, `FLUSHALL`/`FLUSHDB`/`KEYS` reddediliyor, Laravel kuyruk ve kilitleri ACL altında çalışıyor;
  - Plane deploy'u bir Plane turunda yapıldı ✋.

### Faz 3 — CMS hazırlığı (davranış değiştirmez) ✋

- Compose:
  - `REDIS_HOST: ${REDIS_HOST:-redis}`, `REDIS_PORT: ${REDIS_PORT:-6379}`;
  - `REDIS_USERNAME`, `REDIS_PASSWORD` boş varsayılanla;
  - `REDIS_DB: ${REDIS_DB:-0}`, `REDIS_CACHE_DB: ${REDIS_CACHE_DB:-1}`;
  - `REDIS_PREFIX: ${REDIS_PREFIX:-deamon-database-}`. Boş bırakılmaz, yoksa mevcut anahtarlar kaybolur.
- `entrypoint.sh` → `wait_for_redis`: kullanıcı adı ve parola varsa `auth` sonra `ping`.
- Testler: `DeploymentDockerTest` compose satırları ve varsayılanlar, entrypoint.
- Sıra: `alpha` → deamon-test (hâlâ kendi Redis'inde; oturum, kuyruk ve önbellek aynen çalışmalı) → `beta` turu (bütün siteler aynı compose'u alır, davranış değişmez).
- Çıkış: bütün siteler yeni compose ile deploy edildi, hepsi healthy, hata günlüğünde Redis hatası yok.

### Faz 4 — Prova (geçiş yok)

- deamon-test'in Redis verisi paylaşılan sunucuya **kopyalanır ama site geçirilmez**; sayılar ve örnek anahtarlar (TTL dahil) karşılaştırılır, sonra prova şeması (`FLUSHDB` yönetici olarak) silinir.
- ACL kanıtı canlı sunucuda: deamon-test kullanıcısıyla başka DB/önek okuma, `FLUSHALL`, `KEYS` denemeleri reddedilmeli.
- Bellek uyarısının çalıştığı, test DB'sine yazılan geçici yükle doğrulanır.
- Çıkış: kopyalama aracı doğru, ACL kanıtlı, uyarı çalışıyor.

### Faz 5 — deamon-test geçişi ✋

- `move-site-redis.sh test --yes`: bakım (~20–30 sn), kopya, sayım, geçiş, build'siz yeniden yaratma.
- Hemen ardından **geri alma provası** (`--back`: paylaşılan → yerel), sonra yeniden ileri. İki yönün de çalıştığı kanıtlanır.
- 24 saat gözlem:
  - oturum (admin girişi kalıcı mı);
  - kuyruk işleri (e-posta, arka plan görevleri);
  - zamanlayıcı ve `withoutOverlapping`;
  - önbellek;
  - `evicted_keys = 0`;
  - Laravel ve container günlüklerinde Redis/ACL hatası yok;
  - Deamon Test oturumunun kendi kontrolleri.
- Çıkış: 24 saat temiz.

### Faz 6 — Bütün siteler ✋

- `fleet-db-rollout.sh` ile aynı düzen: CPU < %80, birer birer, her siteden sonra sağlık kontrolü; bir site sağlıksız kalırsa durur ve WhatsApp'a uyarı gider.
- Dalgalar:
  1. demo ve kendi sitelerimiz;
  2. küçük müşteriler;
  3. büyükler (izyem, basutsilo).
- Site başına ~20–30 sn bakım. Gece ya da sakin saatte.
- Bitince:
  - Plane'de bütün siteler `redis=shared`;
  - eski Redis container'ları geri dönüş için birkaç gün kalır;
  - sonra compose'tan `redis` servisi, MySQL faz 3 ile **aynı sürümde** kaldırılır ✋ (tek tur build).
- Çıkış: bütün siteler paylaşılan Redis'te, 48 saat sorunsuz; eski container'lar kaldırıldı.

## Süre tahmini

- Faz 0: 0,5 gün.
- Faz 1: 0,5 gün.
- Faz 2: 1–1,5 gün.
- Faz 3: 0,5 gün + bir `beta` turu.
- Faz 4: 0,5 gün.
- Faz 5: 0,5 gün + 24 saat gözlem.
- Faz 6: 36 site için ~1 saat.

Toplam ~4–5 iş günü + gözlem. 200 site hedefinde yeni siteler doğrudan paylaşılan Redis ile açılır (Plane provizyonu, Faz 2'nin parçası).
