<?php

/*
| Deploy hata teşhisi. Kod başına: title (tek satır), cause (neden), steps (operatör
| adımları), commands (sunucuda çalıştırılacak komutlar). Yer tutucular:
| :container (düşen konteyner adı), :exit (çıkış kodu), :uuid (Coolify app uuid),
| :domain (birincil domain), :service (mysql|redis|app|build|git|coolify|host).
*/

return [
    'title' => 'Teşhis',
    'kicker' => 'Plane ne buldu',
    'hint' => 'Coolify çıktısı otomatik sınıflandırıldı. Güvenli düzeltmeler kendiliğinden uygulanır; kalanı için adımlar ve sunucu komutları aşağıda.',
    'evidence' => 'Kanıt satırı',
    'cause' => 'Neden',
    'steps' => 'Yapılacaklar',
    'commands' => 'Sunucuda çalıştır (sunucu erişimi olan kişi)',
    'container_logs' => 'Konteyner logu (Coolify API, son satırlar)',
    'container_logs_missing' => 'Konteyner logu alınamadı: :reason',
    'container_logs_pending' => 'Konteyner logu henüz çekilmedi. “Teşhisi yenile” Coolify’den son satırları alır.',
    'refresh' => 'Teşhisi yenile',
    'refreshed' => 'Teşhis yenilendi.',
    'runbook' => 'Runbook: docs/runbooks/deploy-failure-triage.md#:code',
    'fix_buttons' => 'Plane’den uygula',
    'auto_none' => 'Bu hata için otomatik düzeltme yok; aşağıdaki adımlar gerekiyor.',
    'auto' => [
        'applied' => 'Plane otomatik uyguladı: :fix. Sonucu bir sonraki deploy kaydında görün.',
        'skipped' => 'Otomatik düzeltme (:fix) atlandı: :reason',
        'failed' => 'Otomatik düzeltme (:fix) çalıştırılamadı: :reason',
    ],
    'skip_reasons' => [
        'repeated' => 'aynı hata için az önce zaten uygulandı; tekrar denemek döngü yaratır.',
        'newer_deployment' => 'bu sırada daha yeni bir deploy başladı.',
        'disabled' => 'otomatik düzeltme ayarlardan kapalı.',
        'not_safe' => 'bu düzeltme operatör onayı ister.',
        'no_app' => 'sitenin Coolify uygulaması bağlı değil.',
        'error' => 'Coolify hata döndürdü.',
    ],
    'services' => [
        'mysql' => 'MySQL',
        'redis' => 'Redis',
        'app' => 'Uygulama (CMS)',
        'build' => 'Derleme',
        'git' => 'Git',
        'coolify' => 'Coolify',
        'host' => 'Sunucu',
        'unknown' => 'Bilinmiyor',
    ],
    'codes' => [
        'unknown' => [
            'title' => 'Hata sınıflandırılamadı',
            'cause' => 'Coolify çıktısında bilinen bir kalıp yok. Konteyner logu ve tam rapor incelenmeli.',
            'steps' => [
                'Bu sayfadaki “Raporu kopyala” çıktısını geliştiriciye veya AI’ye yapıştır.',
                'Coolify’de uygulamayı aç, Logs sekmesinde app / mysql / redis konteynerlerinin son satırlarına bak.',
                'Sebep belli olunca bu kalıbı DeploymentFailureClassifier’a ekle; bir sonraki sefer Plane kendisi tanır.',
            ],
            'commands' => [
                'docker ps -a --filter name=:uuid --format "{{.Names}}\t{{.Status}}"',
                'docker logs :container --tail 80',
            ],
        ],
        'mysql_exited' => [
            'title' => 'MySQL konteyneri başlar başlamaz kapandı (exit :exit)',
            'cause' => 'App, MySQL sağlıklı olmadan açılmaz. MySQL bir saniye içinde çıktı; sebep MySQL’in kendi logunda yazar, Coolify deploy logunda değil. Kod değişmedi: aynı commit başka sitede çalışıyorsa sorun bu sitenin volume’u, env’i ya da sunucu kaynağıdır.',
            'steps' => [
                'Aşağıdaki konteyner logunu oku (Plane çekebildiyse burada; yoksa komutu sunucuda çalıştır). [ERROR] satırı sebebi söyler.',
                '“password option is not specified” → env boş: Plane “Env düzelt” + “Tekrar deploy”.',
                '“initialized by a newer version” → volume daha yeni MySQL ile açılmış: compose image sürümünü volume ile eşleştir (runbook).',
                '“Unable to lock ./ibdata1” → eski konteyner volume’u tutuyor: “Durdur ve tekrar deploy”.',
                '“No space left on device” → sunucu diski dolu (runbook: disk temizliği).',
                'Volume’u SİLME: sitede veri varsa geri gelmez. Boş/yeni site ise Coolify’de volume silinip yeniden deploy edilebilir.',
            ],
            'commands' => [
                'docker logs :container --tail 80',
                'docker ps -a --filter name=:uuid --format "{{.Names}}\t{{.Status}}"',
                'df -h /var/lib/docker',
            ],
        ],
        'redis_exited' => [
            'title' => 'Redis konteyneri başlamadı (exit :exit)',
            'cause' => 'Redis genellikle bellek limiti (128M) ya da dolu disk yüzünden düşer; app Redis olmadan açılmaz.',
            'steps' => [
                'Konteyner logunu oku; “Can’t save in background” / “MISCONF” disk, “OOM” bellek demektir.',
                'Disk doluysa runbook’taki temizliği uygula; sonra “Tekrar deploy”.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
                'df -h /var/lib/docker',
                'free -m',
            ],
        ],
        'app_exited' => [
            'title' => 'Uygulama konteyneri sağlıklı hale gelmedi',
            'cause' => 'entrypoint bir adımda çıktı (DB/Redis bekleme, migrate, tema senkron) ya da PHP açılmadı. Sebep app konteyner logunda.',
            'steps' => [
                'App konteyner logunun son satırlarını oku; “Veritabanina … ulasilamadi” DB, “SQLSTATE” migrate hatasıdır.',
                'Geçici sebep (DB geç açıldı) ise “Yeniden başlat” yeter; kalıcıysa loga göre ilgili kodun adımlarını uygula.',
            ],
            'commands' => [
                'docker logs :container --tail 120',
            ],
        ],
        'mysql_no_root_password' => [
            'title' => 'MySQL env boş: root şifresi yok',
            'cause' => 'Volume boş (ilk kurulum) ve MYSQL_ROOT_PASSWORD / DB_PASSWORD Coolify env’de yok ya da placeholder. MySQL şifresiz başlamayı reddeder.',
            'steps' => [
                'Plane “Env düzelt” kataloğa göre şifreleri üretip Coolify’a yazar (yapıldıysa aşağıda görünür).',
                'Ardından “Tekrar deploy”.',
            ],
            'commands' => [],
        ],
        'mysql_data_newer_version' => [
            'title' => 'MySQL volume’u daha yeni bir sürümle açılmış',
            'cause' => 'Volume’daki veri dizini compose’daki image’dan (mysql:8.0) daha yeni bir MySQL ile oluşturulmuş; eski sürüm o veriyi açamaz ve hemen kapanır.',
            'steps' => [
                'Sunucuda volume’daki sürümü oku (komut). Örn. 8.4.x görünüyorsa veri 8.4 ile açılmış demektir.',
                'Seçenek A (hızlı, veri korunur): Coolify’de sadece bu app için compose override ile mysql image’ını volume sürümüne eşitle ve deploy et.',
                'Seçenek B (temiz): geçici konteynerle volume sürümünde mysqldump al, volume’u sil, 8.0 ile boş başlat, dump’ı geri yükle.',
                'Volume’u dump almadan SİLME.',
            ],
            'commands' => [
                'docker logs :container --tail 40',
                'docker run --rm -v $(docker volume ls -q --filter name=:uuid | grep mysql):/var/lib/mysql alpine sh -c "cat /var/lib/mysql/mysql_upgrade_info 2>/dev/null || ls /var/lib/mysql | head"',
            ],
        ],
        'mysql_locked' => [
            'title' => 'MySQL volume’u başka bir konteyner tarafından kilitli',
            'cause' => 'Önceki deploy’un mysql konteyneri hâlâ çalışıyor ya da düzgün kapanmadı; yeni konteyner ibdata1 kilidini alamıyor.',
            'steps' => [
                'Plane “Durdur ve tekrar deploy”: uygulamayı Coolify’de durdurup yeniden başlatır.',
                'Hâlâ kilitliyse sunucuda eski mysql konteynerini bul ve durdur (komut), sonra tekrar deploy.',
            ],
            'commands' => [
                'docker ps --filter name=mysql-:uuid --format "{{.Names}}\t{{.Status}}"',
                'docker stop $(docker ps -q --filter name=mysql-:uuid)',
            ],
        ],
        'mysql_corrupt' => [
            'title' => 'MySQL veri dosyaları bozuk',
            'cause' => 'InnoDB kurtarma başarısız (çoğunlukla OOM-kill ya da disk dolu sırasında yazma kesildi).',
            'steps' => [
                'Volume’u SİLME. Önce son yedeği bul (CMS yedek modülü / Coolify backup).',
                'Geçici konteynerle innodb_force_recovery=1..4 deneyip mysqldump al (runbook).',
                'Dump alındıysa volume’u sıfırla ve geri yükle; alınamadıysa yedekten dön.',
                'Kök nedeni gider: bellek limiti (mem_limit) ve disk.',
            ],
            'commands' => [
                'docker logs :container --tail 120',
                'df -h /var/lib/docker && free -m',
            ],
        ],
        'git_access' => [
            'title' => 'Coolify repoya erişemedi',
            'cause' => 'GitHub App / deploy key yetkisi yok, token süresi dolmuş ya da repo adı yanlış.',
            'steps' => [
                'Plane Ayarlar → Deamon Git bağlantısını ve Coolify’deki Git kaynağını (GitHub App / deploy key) kontrol et.',
                'Coolify UI’da uygulamanın Source alanında repo ve dal doğru mu bak; gerekiyorsa GitHub App’i yeniden yetkilendir.',
                'Sonra “Tekrar deploy”.',
            ],
            'commands' => [],
        ],
        'git_ref_missing' => [
            'title' => 'Pinlenen commit / dal repoda yok',
            'cause' => 'Coolify pinli SHA’yı ya da dalı bulamadı (force-push, silinmiş dal, yanlış SHA).',
            'steps' => [
                '“HEAD’i takip et” ile dalın son commit’ine dön, ya da Dağıtım ayarlarından geçerli bir SHA pinle.',
            ],
            'commands' => [],
        ],
        'registry_rate_limited' => [
            'title' => 'Docker Hub çekme limiti',
            'cause' => 'Sunucu Docker Hub’ın anonim çekme limitine takıldı (mysql/redis image’ı çekilemedi).',
            'steps' => [
                'Plane kısa süre sonra yeniden deploy eder. Sık tekrarlıyorsa Coolify’de Docker Hub hesabı tanımla ya da image’ları önbelleğe al.',
            ],
            'commands' => [],
        ],
        'disk_full' => [
            'title' => 'Sunucu diski dolu',
            'cause' => 'Build ya da konteyner “No space left on device” aldı. Eski image, build cache ve log dosyaları diski doldurur; birden çok site aynı anda etkilenir.',
            'steps' => [
                'Sunucuda kullanımı gör ve güvenli temizliği çalıştır (komutlar). Volume SİLME.',
                'Temizlik sonrası etkilenen siteleri “Tekrar deploy” (Siteler listesinden toplu).',
                'Kalıcı çözüm: disk büyütme ya da Coolify’de otomatik image temizliği.',
            ],
            'commands' => [
                'df -h && docker system df',
                'docker image prune -af --filter "until=72h" && docker builder prune -af --filter "until=72h"',
                'journalctl --vacuum-size=200M',
            ],
        ],
        'oom_killed' => [
            'title' => 'Konteyner bellek yetersizliğinden öldürüldü (exit 137)',
            'cause' => 'Konteyner mem_limit’i (app 768M, mysql 768M) aştı ya da sunucu genel belleği bitti. restart: no olduğu için konteyner geri gelmez.',
            'steps' => [
                '“Yeniden başlat” siteyi hemen ayağa kaldırır.',
                'Tekrarlıyorsa CMS compose’unda mem_limit’i artır ya da sunucudaki eşzamanlı build sayısını düşür.',
            ],
            'commands' => [
                'docker inspect :container --format "{{.State.OOMKilled}} {{.State.ExitCode}}"',
                'free -m && dmesg | grep -i "killed process" | tail -5',
            ],
        ],
        'port_conflict' => [
            'title' => 'Port çakışması',
            'cause' => 'Aynı portu dinleyen eski bir konteyner ya da süreç var.',
            'steps' => [
                '“Durdur ve tekrar deploy”. Devam ederse sunucuda portu tutan süreci bul.',
            ],
            'commands' => [
                'docker ps --format "{{.Names}}\t{{.Ports}}" | grep -i :uuid',
            ],
        ],
        'docker_daemon' => [
            'title' => 'Docker daemon’a ulaşılamadı',
            'cause' => 'Sunucudaki Docker servisi yanıt vermiyor ya da Coolify helper konteyneri açılamadı.',
            'steps' => [
                'Sunucuda Docker servisini kontrol et, gerekirse yeniden başlat; sonra “Tekrar deploy”.',
            ],
            'commands' => [
                'systemctl status docker --no-pager | head -20',
                'docker info | head -30',
            ],
        ],
        'app_db_unreachable' => [
            'title' => 'Uygulama MySQL’e bağlanamadı',
            'cause' => 'App entrypoint 60 saniye içinde DB’ye ulaşamadı; MySQL geç açıldı ya da şifre uyuşmuyor.',
            'steps' => [
                'Plane “Yeniden başlat” uygular (DB artık hazırsa açılır).',
                'Tekrarlıyorsa Coolify env’de DB_PASSWORD ile MySQL’deki şifre farklı olabilir: mysql logunda “Access denied” ara.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
            ],
        ],
        'app_redis_unreachable' => [
            'title' => 'Uygulama Redis’e bağlanamadı',
            'cause' => 'Redis konteyneri hazır değildi ya da kapandı.',
            'steps' => [
                'Plane “Yeniden başlat” uygular. Tekrarlıyorsa redis konteyner loguna bak.',
            ],
            'commands' => [
                'docker logs :container --tail 60',
            ],
        ],
        'migration_failed' => [
            'title' => 'Veritabanı migration’ı başarısız',
            'cause' => 'Yeni sürümün migration’ı bu sitenin verisinde hata verdi (SQLSTATE). Kod ya da veri kaynaklı; tekrar deploy çözmez.',
            'steps' => [
                '“Son çalışan sürüme dön” siteyi hemen ayağa kaldırır (pin).',
                'SQLSTATE satırını geliştiriciye ilet; düzeltme CMS’e girince HEAD’i takip et.',
            ],
            'commands' => [
                'docker logs :container --tail 120 | grep -i -A5 SQLSTATE',
            ],
        ],
        'volume_permission' => [
            'title' => 'Volume izin hatası',
            'cause' => 'Konteyner volume’a yazamıyor (sahiplik/izin). Genelde elle kopyalanmış veri ya da farklı UID.',
            'steps' => [
                'Sunucuda volume sahipliğini düzelt (mysql için 999:999, app storage için www-data), sonra “Tekrar deploy”.',
            ],
            'commands' => [
                'docker logs :container --tail 40',
                'docker volume ls --filter name=:uuid',
            ],
        ],
        'build_failed' => [
            'title' => 'Image derlemesi başarısız',
            'cause' => 'Dockerfile / npm / composer adımı hata verdi. Sebep koddadır; aynı commit başka sitede de düşer.',
            'steps' => [
                '“Son çalışan sürüme dön” ile siteyi eski sürümde tut.',
                'Build logundaki ilk ERROR satırını geliştiriciye ilet. Düzeltme gelince “HEAD’i takip et”.',
            ],
            'commands' => [],
        ],
        'compose_domains_before_raw' => [
            'title' => 'Domain bağlanamadı: compose henüz yüklenmedi',
            'cause' => 'Coolify compose dosyasını git’ten okumadan domain yazılamaz. Sıralama sorunu, ayar değil.',
            'steps' => [
                'Plane yeniden deploy eder; bitince domaini otomatik bağlar (“Domain bağla”).',
            ],
            'commands' => [],
        ],
        'no_deployment_uuid' => [
            'title' => 'Coolify deploy kimliği dönmedi',
            'cause' => 'Deploy isteği kabul edildi ama Coolify uuid vermedi; Plane sonucu izleyemedi. Deploy Coolify’de sürmüş olabilir.',
            'steps' => [
                'Plane deploy geçmişini Coolify’den senkronlar; gerçek durum bu satıra yazılır.',
            ],
            'commands' => [],
        ],
        'coolify_timeout' => [
            'title' => 'Plane deploy sonucunu beklerken zaman aşımı',
            'cause' => 'Build uzun sürdü ya da webhook gelmedi. Coolify tarafında deploy bitmiş olabilir.',
            'steps' => [
                'Plane Coolify’den senkronlar; gerçek sonuç “finished” ise site kendiliğinden aktif olur.',
            ],
            'commands' => [],
        ],
        'coolify_rate_limited' => [
            'title' => 'Coolify istek sınırı (429)',
            'cause' => 'Toplu işlem sırasında Coolify API yavaşlattı; deploy durumu okunamadı, deploy başarısız olmayabilir.',
            'steps' => [
                'Plane senkronlar. Sık oluyorsa toplu deploy’ları daha küçük gruplarla çalıştır.',
            ],
            'commands' => [],
        ],
    ],
];
