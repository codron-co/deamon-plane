# Related infrastructure

Plane ile birlikte konuşulan ama **bu repoda uygulanmayan** komşu sistemler.

## Mailcow (ortak mail)

**Karar (ADR-8):** Tek ortak Mailcow (veya HA çifti). Her müşteriye ayrı Mailcow kurulmaz.

**CoMail (2026-10-26):** the shared mailcow runs on main.codron (`https://mail.codron.co`, behind Coolify Traefik). Plane is wired to it: mailcow API client, packages, per-site mail domains, DNS, CMS configure push and the CMS → Plane mailbox API. Details: [modules/comail.md](modules/comail.md). The mailcow server itself (install, updates, backups, Rspamd) is still operated outside this repo.

**Hostinger (in scope):** [modules/mail-servers.md](modules/mail-servers.md). Token in Plane; CMS plugin via HMAC configure + reverse mailbox proxy.

| | |
|--|--|
| Model | Hostinger (mevcut siteler) / CoMail = ortak mailcow (yeni siteler): müşteri başına domain + mailbox, paket sınırları Plane’de |
| Plane entegrasyonu | Hostinger v1 done. CoMail (mailcow) v1 done: [modules/comail.md](modules/comail.md). |

### Neden ortak?

- ×35 Mailcow = kaynak, IP itibarı, yama yükü
- Mailcow native multi-domain
- Ayrı instance yalnızca compliance / dedicated IP / ağır blacklist izolasyonu için

### Risk

Ortak outbound IP: bir domain spam yaparsa itibar paylaşılır → SPF/DKIM/DMARC, kota, Rspamd zorunlu.

## Coolify

Hem **müşteri siteleri** hem **Plane** Coolify’de ayrı uygulamalar; ikisi de **Docker Compose** build pack.

| | Git | Compose | Volumes |
|--|-----|---------|---------|
| Plane | `codron-co/deamon-plane` | `docker-compose.coolify.yml` | `plane_storage`, `plane_mysql`, `plane_redis` |
| Site | `codron-co/deamon` | `docker-compose.coolify.yml` | `deamon_storage`, `deamon_themes`, `deamon_mysql`, `deamon_redis` |

Nixpacks / tek Dockerfile pack yok. Detay: [modules/deployment.md](modules/deployment.md), plan §5.

## Theme org

Tarihsel: `deamon-themes` / `deamon-theme-{id}`. **2026-09-10:** birden fazla GitHub user/org connection (Themes Manifest). Push webhook → plane → agent update (opt-in). ZIP yok. Detay: [modules/theme-catalog.md](modules/theme-catalog.md).
