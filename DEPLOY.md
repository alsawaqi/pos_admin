# pos_admin — production deploy (posadmin.mithqal.net)

Runs on the VPS as a Docker Compose stack that **joins the existing external
`charity_net` network** and shares the charity Postgres (`chariyt-db` /
`charity_db`) with the charity API and pos_merchant.

## Prerequisites (already true if charity-laravel-api is live)

- The external network exists: `docker network create charity_net` (once).
- The shared Postgres container is up on `charity_net`, reachable as host
  `chariyt-db`, database `charity_db`.
- A host reverse proxy / TLS terminator is in front (the same one charity uses).

## 1. Configure `.env`

```bash
cp src/.env.production.example src/.env
# Edit src/.env and set:
#   APP_KEY        (generate once — see below — and reuse it in pos_merchant)
#   DB_PASSWORD    (the charity_db password)
#   POS_ADMIN_DEFAULT_PASSWORD, VITE_GOOGLE_MAPS_API_KEY, SCALEFUSION_TOKEN, ...
```

Generate the shared app key once and paste the **same** value into both
pos_admin and pos_merchant `.env`:

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm artisan php artisan key:generate --show
```

## 2. Build code + assets

```bash
docker compose -f docker-compose.prod.yml --profile build run --rm composer
docker compose -f docker-compose.prod.yml --profile build run --rm node-build
docker compose -f docker-compose.prod.yml --profile init  run --rm init-perms   # first deploy only
```

## 3. Migrate (ADDITIVE — safe on the shared DB)

```bash
docker compose -f docker-compose.prod.yml --profile migrate run --rm artisan
```

`php artisan migrate --force` only runs **pos_admin's own** un-run migrations
and records them in the **`pos_admin_migrations`** table — charity's
`migrations` table and tables are never touched (the `ensure_*_stub`
migrations are `hasTable()`-guarded no-ops in production).

> ⚠️ NEVER run `migrate:fresh`, `migrate:reset`, `migrate:rollback`, or
> `db:wipe` against this database — it is shared and live.

## 4. Start + cache

```bash
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml --profile deploy run --rm deploy
```

## 5. Reverse proxy

The `nginx` service has **no host ports** — it exposes `:80` on `charity_net`
under the alias **`pos-admin-web`**. Point the host proxy at it, e.g.:

```nginx
server {
    server_name posadmin.mithqal.net;
    # ... your TLS / certbot config ...
    location / {
        proxy_pass http://pos-admin-web:80;     # resolvable if the proxy is on charity_net
        proxy_set_header Host              $host;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;   # required — Laravel trusts this
        proxy_set_header X-Forwarded-Host  $host;
    }
}
```

(If your proxy is not on `charity_net`, either attach it to that network or
publish the nginx service on a localhost port and proxy to that instead.)

## Updating a release

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml --profile build   run --rm composer
docker compose -f docker-compose.prod.yml --profile build   run --rm node-build
docker compose -f docker-compose.prod.yml --profile migrate run --rm artisan   # if new migrations
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml --profile deploy  run --rm deploy
```
