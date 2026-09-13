# Decisions (ADR)

| ID | Karar | Gerekçe |
|----|-------|---------|
| ADR-1 | Ayrı repo (`deamon-plane`) / ayrı Coolify app | Deamon MVP tek-site; blast radius; güvenlik |
| ADR-2 | Site başına MySQL+Redis | Kuyruk/cache karışması yok; mevcut compose |
| ADR-3 | Kanal = Coolify git branch + redeploy | Mevcut `main`/`beta`/`alpha` düzeni |
| ADR-4 | Tema = git clone/pull via agent, ZIP yok | Trusted code; org ile hizalı |
| ADR-5 | Internal ops only v1 | Auth/izolasyon basit |
| ADR-6 | Coolify API + agent; SSH primitif değil | Audit edilebilir otomasyon |
| ADR-7 | Channel switch volume’ları korur | DB/medya/tema persist |
| ADR-8 | Mailcow **ortak** (tek instance, çok domain) | Hostinger modeli; ×35 Mailcow maliyet/itibar yükü |
| ADR-9 | Mailcow plane v1 kapsamı dışı | Ayrı ürün; ileride mailbox API opsiyonel |
| ADR-10 | `health` / `app` list filtreleri için persisted verdict | PHP verdict’i filtre yolunda taramak P0-2’yi geri getirir; kolon `SiteFilterVerdict` ile yazılıyor, filtre SQL |
| ADR-11 | DOM harness = `node --test` + jsdom; Playwright/Dusk yok | Pest `data-*` sözleşmesini tutar; `tests/js` + `public/js/ops-contracts.js` (toolbar URL, poll backoff, row identity, `__COUNT__` interpolate, `isTyping` / `confirmOpen`, `pageIsHidden` / `shouldSchedulePoll`, `textMatches`, list `fetch` / `replaceState`). jsdom yok — `document.hidden` ördeği `{ hidden: true }`; list headers / `{ origin, pathname }` |

Detaylı plan: [../plans/2026-08-13-deamon-plane.md](../plans/2026-08-13-deamon-plane.md). Mail: [../related-infra.md](../related-infra.md).
