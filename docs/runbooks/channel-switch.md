# Runbook: Channel switch

> Stub — Task 5 sonrası doldurulacak. Volume kuralı Dalga 0’dan:

1. Confirm (+ version gate)
2. Switch = Coolify `PATCH` `git_branch` + `POST /deploy` — **application silme/yeniden oluşturma yok**
3. Deploy finished
4. Health + smoke
5. Fail → **manuel** rollback channel (v1)

**Yasak:** `DELETE /applications/{uuid}` (query `delete_volumes` default **true** — MySQL/Redis/themes/storage gider). Paylaşımlı DB/Redis ile “düzeltme” yok.
