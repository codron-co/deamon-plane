# Runbook: Deploy Plane (Coolify Compose)

SoT: [docs/modules/deployment.md](../modules/deployment.md)

1. Coolify → New Resource → Git `codron-co/deamon-plane`
2. Build pack **Docker Compose** → file **`docker-compose.coolify.yml`**
3. Domain on service **`app`**, health `/up` port **8080**
4. Env: `APP_KEY` only at first; later `COOLIFY_*` / `GITHUB_*`
5. Do not set DB/Redis in Coolify env (compose owns them)
6. First green deploy: Laravel scaffold is on the branch (`composer.json`); image runs `composer install` + `artisan migrate` on boot
7. Restrict access (IP/VPN) before importing the fleet
