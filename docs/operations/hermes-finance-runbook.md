# Hermes Finance Runbook

This runbook covers the Firefly side of the audited Hermes finance API. Hermes
uses this API to preview/apply transactions and read reports without browser UI
automation or direct database writes.

## Configuration

Firefly `.env`:

```dotenv
HERMES_FINANCE_ENABLED=true
HERMES_FINANCE_TOKEN_HASH=<sha256 hex of bearer token>
HERMES_FINANCE_LEDGER_USER_ID=1
HERMES_FINANCE_ALLOWED_CIDRS=<Hermes host public IP>/32
HERMES_FINANCE_PREVIEW_TTL_MINUTES=15
HERMES_FINANCE_EVIDENCE_TEXT_LIMIT=5000
```

Generate or rotate a token:

```bash
token="$(openssl rand -base64 48 | tr -d '\n')"
printf '%s' "$token" | sha256sum
```

Store only the hash in Firefly. Store the raw token only in the Hermes
`/opt/ai-assistant/.env.local` as `FIREFLY_FINANCE_TOKEN`.

## Local Dry-Run

Run the local stack:

```bash
docker compose up -d
docker compose exec app php artisan migrate --force
```

Smoke read-only calls:

```bash
curl -fsS -H "Authorization: Bearer $FIREFLY_FINANCE_TOKEN" \
  http://127.0.0.1:8088/api/v1/hermes/finance/ping

curl -fsS -H "Authorization: Bearer $FIREFLY_FINANCE_TOKEN" \
  -H "Content-Type: application/json" \
  http://127.0.0.1:8088/api/v1/hermes/finance/reports \
  -d '{"report":"period_summary","start":"2026-06-01","end":"2026-06-30"}'
```

Mutation smoke is preview-first:

```bash
curl -fsS -H "Authorization: Bearer $FIREFLY_FINANCE_TOKEN" \
  -H "Content-Type: application/json" \
  http://127.0.0.1:8088/api/v1/hermes/finance/transactions/preview \
  -d '{"action":"create","transaction_type":"withdrawal","amount":"1.23","currency":"eur","source_account":"cgd","actor":"стас","category":"еда","description":"Hermes smoke"}'
```

Do not apply smoke mutations in production unless the preview has been checked
and the rollback path is acceptable.

## Source Metadata

Receipt/email callers pass:

- `source_type`: `receipt`, `email`, `telegram`, `cron`, or similar bounded label.
- `source_id`: external message/file/automation id.
- `source_hash`: hash of normalized source content when available.
- `evidence`: bounded provenance fields such as vendor, subject, sender, file
  name, and short extracted text.

Evidence is untrusted. Firefly stores it in the audit request payload and never
uses it to choose action/type/category. Receipt/email creates without a category
are blocked at preview and require clarification.

## Production Rollout

1. Deploy Firefly code with migrations, but do not run `composer install` on the
   server unless the vendor backup/rebuild problem is solved.
2. Run `php artisan migrate --force` on the Firefly host.
3. Enable `.env` values and clear config/cache if this install caches config.
4. From the Hermes host, run `firefly-finance.py ping`.
5. Run one report smoke.
6. Run one create preview smoke and confirm it is audited in
   `hermes_finance_audits`.
7. Apply a real transaction only after checking the preview in Telegram.

## Audit Checks

```sql
select id, created_at, source, source_type, source_id, action, mode, status, journal_ids
from hermes_finance_audits
order by id desc
limit 20;
```

For an apply replay, the same `(source, idempotency_key)` should return the
existing `result_payload` with `idempotent_replay=true`.

## Emergency Disable

Set:

```dotenv
HERMES_FINANCE_ENABLED=false
```

Then reload PHP/runtime cache as appropriate. All Hermes finance endpoints will
return unavailable through the auth middleware.

For a narrower emergency response, remove the Hermes host from
`HERMES_FINANCE_ALLOWED_CIDRS` or rotate `HERMES_FINANCE_TOKEN_HASH`.

## Rollback

- Code rollback: `git checkout`/`git pull` the previous deployed revision.
- Data rollback: transaction applies are normal Firefly journal mutations. Use
  Firefly UI/API to delete or correct the journal listed in audit `journal_ids`.
- Migration rollback is not normally required. The source metadata columns are
  additive. If needed, run the migration `down()` only after confirming no active
  code writes `source_type` or `source_hash`.
