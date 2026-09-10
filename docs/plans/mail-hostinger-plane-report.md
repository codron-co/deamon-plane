# Hostinger mail — Plane implementer report

**Status: DONE**

Plane holds Hostinger API tokens. CMS never receives them. Mailcow cannot be saved (Coming soon).

## Tests

```
php artisan test --filter="MailServerOpsTest|SiteMailAssignTest|SiteMailProxyTest"
vendor/bin/pint --dirty
```

## Concerns

None that block. Inbound HMAC uses per-site nonce cache. Hostinger list/create JSON shapes follow `data` + `email`/`address` aliases to match the CMS client.
