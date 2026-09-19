# Runbook: Deploy hatası teşhisi ve giderme

Internal ops only. Secret, token, `APP_KEY` yapıştırma. Volume silme kararı yalnızca veri yedeği doğrulandıktan sonra verilir.

Plane her **başarısız** deploy satırını kendisi sınıflandırır (`DeploymentFailureClassifier`), gerekiyorsa konteyner logunun son satırlarını Coolify API’den çeker (`GET /applications/{uuid}/logs`) ve kodun izin verdiği **tek güvenli düzeltmeyi** kendisi uygular (`DeploymentDiagnoser`, `DiagnoseDeploymentJob`). Sonuç deploy detayındaki **Teşhis** kartında görünür: neden, kanıt satırı, Plane’in otomatik yaptığı, operatörün yapacağı adımlar ve sunucu komutları. App sağlığı satırı da aynı kodu gösterir (`deploy_diagnosed`) ve ilk düzeltmeyi buton olarak sunar.

Sunucu erişimi Plane’de yok. “Sunucuda çalıştır” komutları sunucuya girebilen kişiye (Emin) iletilir; çıktısı Plane’deki rapora eklenir.

## Akış (otomatik)

1. Deploy `failed` olur (poll, webhook, sync, provision, kanal geçişi — hepsi `Deployment` kaydına yazar).
2. `Deployment::saved` → `DiagnoseDeploymentJob` (commit sonrası, satır başına tek sefer).
3. Sınıflandırma: Coolify `error_message` + `log_excerpt` (+ varsa konteyner logu). Kural sırası: konteyner içi hata (MySQL şifre, sürüm, kilit, bozulma) → git/registry → sunucu (disk, OOM, port, docker) → app (DB/Redis erişimi, migration) → compose semptomu (hangi konteyner düştü) → build → Coolify/Plane (timeout, 429, uuid yok).
4. Kod “konteyner logu gerekir” diyorsa Coolify’den son 200 satır çekilir, secret’lar maskelenir, 8 KB kuyruk saklanır ve sınıflandırma logla tekrar yapılır (semptom → gerçek neden).
5. Otomatik düzeltme: yalnızca `AUTO_SAFE` (`sync_env`, `redeploy`, `restart_app`, `sync_deployments`, `bind_domains`). Koşullar: `ops.diagnosis.auto_fix` açık, site Coolify’a bağlı, bu sırada daha yeni deploy yok, **aynı düzeltme son 24 saatte aynı sitede zaten uygulanmamış** (döngü kilidi). Sonuç `diagnosis.auto_fix` olarak satıra ve `site.deploy_auto_fix` audit’e yazılır.
6. Ops mail (`deploy_failed`) ve App sağlığı kartı teşhis başlığını gösterir.

**Teşhisi yenile** (deploy detayı) logu tekrar çeker ve sınıflandırır; otomatik düzeltmeyi **tekrar çalıştırmaz**.

## Kod kataloğu

| Kod | Servis | Kanıt | Plane otomatik | Operatör |
|-----|--------|-------|----------------|----------|
| `mysql_exited` | mysql | `dependency failed to start: container mysql-… exited (1)` | Konteyner logunu çeker; loga göre alttaki kodlardan birine iner | Log Plane’de yoksa: `docker logs <mysql-container> --tail 80`. Volume silme. |
| `mysql_no_root_password` | mysql | `Database is uninitialized and password option is not specified` | **Env düzelt** (katalogdan şifre üretir) | Ardından Tekrar deploy |
| `mysql_data_newer_version` | mysql | `initialized by a newer version` / `Data dictionary upgrade` | — | Volume sürümünü oku; A) compose image’ı volume sürümüne eşitle, B) dump → volume sıfırla → 8.0 → restore. Dump’sız volume silme. |
| `mysql_locked` | mysql | `Unable to lock ./ibdata1` | — | **Durdur ve tekrar deploy**; sürerse eski mysql konteynerini `docker stop`. |
| `mysql_corrupt` | mysql | `InnoDB: Assertion failure`, `corrupt`, `checksum mismatch` | — | Yedeği bul; `innodb_force_recovery` ile dump; restore. Kök neden: OOM/disk. |
| `redis_exited` | redis | `dependency failed to start: container redis-…` | Log çeker | `docker logs`, disk/bellek; Tekrar deploy |
| `app_exited` | app | `container app-… is unhealthy/exited` | Log çeker | Loga göre `app_db_unreachable` / `migration_failed` adımları; geçiciyse Yeniden başlat |
| `app_db_unreachable` | app | `Veritabanina 60 saniye icinde ulasilamadi`, `SQLSTATE[HY000] [2002]` | **Yeniden başlat** | Tekrarlarsa DB_PASSWORD ≠ MySQL şifresi: mysql logunda `Access denied` |
| `app_redis_unreachable` | app | `Redis'e 60 saniye icinde ulasilamadi` | **Yeniden başlat** | redis logu |
| `migration_failed` | app | `SQLSTATE[…]`, `QueryException` | — | **Son çalışan sürüme dön** (pin); SQLSTATE satırını geliştiriciye; düzeltme gelince HEAD’i takip et |
| `oom_killed` | host | `exited (137)`, `OOMKilled`, `Out of memory` | — (Yeniden başlat sunulur) | Yeniden başlat; tekrarlarsa CMS compose `mem_limit` artır ya da eşzamanlı build sayısını düşür |
| `disk_full` | host | `No space left on device` | — | `df -h && docker system df`; `docker image prune -af --filter until=72h`; `docker builder prune -af`; sonra etkilenen siteleri toplu Tekrar deploy. Volume silme. |
| `port_conflict` | host | `port is already allocated` | — | Durdur ve tekrar deploy; sürerse portu tutan süreç |
| `docker_daemon` | host | `Cannot connect to the Docker daemon` | — | `systemctl status docker`; gerekirse restart; Tekrar deploy |
| `volume_permission` | host | `Permission denied`, `chown: cannot` | — | Volume sahipliği (mysql 999:999, app www-data); Tekrar deploy |
| `git_access` | git | `could not read Username`, `Repository not found`, `Permission denied (publickey)` | — | Coolify Git kaynağı / GitHub App yetkisi; Ayarlar → Deamon Git; Tekrar deploy |
| `git_ref_missing` | git | `couldn't find remote ref`, `reference is not a tree` | — | **HEAD’i takip et** ya da geçerli SHA pinle |
| `registry_rate_limited` | build | `toomanyrequests`, `pull rate limit` | **Tekrar deploy** | Sık olursa Coolify’a Docker Hub hesabı |
| `build_failed` | build | `failed to solve`, `npm ERR!`, `did not complete successfully` | — | **Son çalışan sürüme dön**; ilk ERROR satırı geliştiriciye |
| `compose_domains_before_raw` | coolify | `docker_compose_domains without docker_compose_raw` | **Tekrar deploy** | Bitince Domain bağla (otomatik) |
| `no_deployment_uuid` | coolify | `did not return a deployment uuid` | **Coolify’den senkronla** | — |
| `coolify_timeout` | coolify | `Timed out waiting for Coolify deployment` | **Coolify’den senkronla** | Gerçek sonuç finished ise site kendiliğinden aktif |
| `coolify_rate_limited` | coolify | `Too Many Attempts` / 429 | **Coolify’den senkronla** | Toplu deploy’ları küçült |
| `unknown` | — | kalıp yok | Log çeker | “Raporu kopyala” → geliştirici/AI; kalıbı `DeploymentFailureClassifier`’a ekle |

## “Site yedek sayfa gösteriyor” (deploy başarılı ama site açılmıyor)

Belirti: `https://<domain>/` **CodRon | Your domain is ready** sayfası; Plane deploy “finished”, agent health “agent_not_registered” gibi görünür. Gerçek: app konteyneri çökmüş (OOM, entrypoint hatası) ve compose `restart: no` olduğu için geri gelmemiş; proxy host’u bulamayıp yedek sayfaya düşmüş. Plane bunu artık ayırt eder:

- Agent health polling yedek sayfayı tanır → `proxy_fallback` → App sağlığında **`app_not_running`** + **Yeniden başlat** butonu.
- Coolify inspect (`InspectSiteAppHealthJob`, “Coolify’den kontrol et”) uygulama durumu `exited` / `degraded` ise aynı sorunu üretir (durdurulmuş site hariç).
- **Yeniden başlat** = `POST /applications/{uuid}/restart` (rebuild yok, saniyeler). Tekrar çökerse sebep konteyner logundadır: `docker logs app-<uuid>-<ts> --tail 120` ve `docker inspect … --format '{{.State.OOMKilled}} {{.State.ExitCode}}'`.
- CMS 1.2.29+ compose `restart: on-failure:3` taşır: çöken konteyner 3 kez kendiliğinden kalkar, host reboot’unda toplu stampede olmaz (`on-failure` daemon restart’ında konteyner başlatmaz).

## Yol haritası (otomasyon yetmediğinde)

1. **Sunucu erişimi olmadan log:** Coolify `logs` ucu çalışan konteynerlerin logunu döndürür; çıkmış (exited) konteyner için boş dönebilir (`container_logs_error=empty`). O zaman komutlar sunucuya iletilir. Kalıcı çözüm: Coolify’de **Logs** sekmesi (UI) ya da Plane’e sunucu SSH (read-only `docker logs`) yetkisi — karar Emin’in.
2. **Aynı hata 2+ sitede:** `disk_full`, `oom_killed`, `docker_daemon`, `registry_rate_limited` sunucu geneli sorunlardır; tek tek redeploy etme. Önce sunucu (df/free/docker), sonra Siteler listesinden toplu Tekrar deploy.
3. **Volume kararı:** `mysql_data_newer_version` / `mysql_corrupt` için sıfırlama yalnızca dump veya yedek doğrulandıktan sonra. Yeni (içeriksiz) site ise Coolify’de volume silinip provision tekrarlanabilir.
4. **Yeni kalıp:** teşhis `unknown` kaldıysa raporu geliştiriciye ver; kalıp `DeploymentFailureClassifier::RULES` + iki dil dosyası + bu tablo. Test: `DeploymentFailureClassifierTest`.
5. **Kapatma anahtarları:** `OPS_DIAGNOSIS_ENABLED=false` (teşhis tamamen), `OPS_DIAGNOSIS_AUTO_FIX=false` (yalnızca otomatik düzeltme). Varsayılan ikisi de açık.

## WetSan (2026-09-19) örneği

- Belirti: 10 Eylül’den beri her deploy `dependency failed to start: container mysql-alaibjmbwyug8jpj1uigndk1-… exited (1)`. Aynı commit makermak’ta çalışıyor → kod değil, sitenin volume/env/sunucu durumu.
- Plane teşhisi: `mysql_exited` → konteyner logu → alt kod. Log çekilemezse Emin: `docker logs mysql-alaibjmbwyug8jpj1uigndk1-<ts> --tail 80`, `docker ps -a --filter name=alaibjmbwyug8jpj1uigndk1`, `df -h /var/lib/docker`.
- Beklenen alt kodlar ve yolu: şifre → Env düzelt + Tekrar deploy; sürüm → image eşitle/dump; kilit → Durdur ve tekrar deploy; disk → temizlik. Volume’a veri yedeği olmadan dokunulmaz.
