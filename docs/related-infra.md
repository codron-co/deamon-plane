# Related infrastructure

Plane ile birlikte konuşulan ama **bu repoda uygulanmayan** komşu sistemler.

## Mailcow (ortak mail)

**Karar (ADR-8):** Tek ortak Mailcow (veya HA çifti). Her müşteriye ayrı Mailcow kurulmaz.

Plane UI: provider select shows Mailcow as **Coming soon** and rejects `provider=mailcow`. No Mailcow API client.

**Hostinger (in scope):** [modules/mail-servers.md](modules/mail-servers.md). Token in Plane; CMS plugin via HMAC configure + reverse mailbox proxy.

| | |
|--|--|
| Model | Hostinger (now) / Mailcow (later): müşteri başına domain + mailbox |
| Plane entegrasyonu | Hostinger v1 done. Mailcow API yok. |

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

`deamon-themes` (veya config) altında `deamon-theme-{id}`; push webhook → plane → agent update (opt-in).
