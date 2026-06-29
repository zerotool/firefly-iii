# Local Docker Development

This fork must be developed through Docker because the host PHP version is newer
than Laravel 5.8 supports. The local stack uses PHP 7.4, Apache, MariaDB 10.11,
and Redis.

## Start

```bash
docker compose config
docker compose build app
docker compose run --rm app composer install
docker compose up -d
docker compose exec app php artisan passport:keys --force
docker compose exec app php artisan route:list
```

The app is served at `http://localhost:8088` by default. Override with
`FIREFLY_HTTP_PORT=8089 docker compose up -d` if that port is already taken.
MariaDB is exposed on local port `3307` for operator tools.

## Fresh Database

```bash
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan passport:install
docker compose exec app php artisan firefly:upgrade-database
docker compose exec app php artisan firefly:verify
```

Do not run migrations automatically from the container command. Keep schema
changes explicit so production dump imports can be tested safely.

## Private Production Dump

Production dumps are allowed only for private local validation. Keep them out of
git and out of shared artifacts.

Recommended local paths:

```text
database-dumps/
dumps/
storage-dumps/
```

Example flow:

```bash
# On production, create a transactionally consistent dump.
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  "$DB_DATABASE" > firefly-prod.sql

# Copy it privately to this machine, then import into the local DB.
docker compose exec -T db mysql -ufirefly -pfirefly firefly < database-dumps/firefly-prod.sql
docker compose exec app php artisan firefly:upgrade-database
docker compose exec app php artisan firefly:verify
```

Regenerate local-only app keys or Passport material only when using a local-only
database. Never commit dump files, generated exports, bank TSV/CSV files, or
secret-bearing notes.

## Hermes Finance API

Enable the local audited finance API only with a local token hash:

```bash
token=local-hermes-dev-token
hash="$(printf '%s' "$token" | shasum -a 256 | awk '{print $1}')"
docker compose exec app sh -lc "printf '\nHERMES_FINANCE_ENABLED=true\nHERMES_FINANCE_TOKEN_HASH=$hash\nHERMES_FINANCE_LEDGER_USER_ID=1\n' >> .env"
docker compose exec app php artisan config:clear
docker compose exec app php artisan migrate --force
```

Smoke from this repository:

```bash
curl -fsS -H "Authorization: Bearer $token" \
  http://127.0.0.1:8088/api/v1/hermes/finance/ping
```

For the Hermes helper in `ai-assistant`:

```bash
FIREFLY_FINANCE_BASE_URL=http://127.0.0.1:8088 \
FIREFLY_FINANCE_TOKEN="$token" \
python3 /Users/stan/Sites/ai-assistant/hermes/scripts/firefly-finance.py ping
```
