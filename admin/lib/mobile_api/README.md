# MyClubHub Mobile API v1

Base URL: `https://myclubhub.co.uk/api/v1`

Machine-readable contract: `GET /api/v1/openapi.json`. The checked-in `openapi.json` is the source contract. Native iOS/Android clients can use the same API; no app framework is required by the server.

## Included

- Public club identity, seasons, upcoming fixtures, results, news, players, cached league standings.
- Login using existing account email/password, rotating refresh tokens, logout, logout-all, session listing and revocation.
- Own profile with limited editing, own season passes, own/assigned match tickets, and person-linked orders with line items.
- JSON errors, request IDs, bounded pagination, request limits, rate limiting, an audit trail, and OpenAPI schemas.

This API serves the existing single club installation. It does not claim tenant isolation across multiple clubs. `/club` supplies the existing shared-account password recovery form at `/admin/forgot_password.php`. The site's `/members/` directory is currently blocked by a pre-existing `.htaccess` rule; that rule is unchanged, and `registration_url` is null. API registration, email/password changes, purchases/payment creation, dependent management, staff administration, push notification registration/delivery, and offline admission are not part of v1.

## App integration

1. POST `/auth/login` with JSON `{"email":"member@example.com","password":"…","device_name":"My iPhone"}`.
2. Securely store `data.access_token` and `data.refresh_token` in the device's Keychain/Keystore, not in URLs or logs.
3. Send `Authorization: Bearer <access_token>` on private requests. Browser session cookies are never used for API authentication.
4. When access expires (15 minutes), POST `/auth/refresh` with `{"refresh_token":"…"}`. Atomically replace both tokens with the new pair.
5. Serialize refresh requests. A refresh token is single-use; reuse revokes the entire device session, including the newly rotated token. After an ambiguous network failure during refresh, require a fresh login rather than retrying an old token. Refresh expiry slides up to 30 days, capped at 90 days from login.
6. POST `/auth/logout` to revoke the current session, or `/auth/logout-all` to revoke all app sessions. These operations and session DELETE return 204 without a body. Website password changes, account deactivation, and person deactivation invalidate existing API sessions on subsequent requests.

The app must treat tokens and ticket QR payloads as secrets. API tokens are stored as SHA-256 hashes on the server. Authentication remains dependent on the existing account password hash. Password rehashes also invalidate API sessions; changing the password back does not restore an old hash.

## Responses and data rules

Success: `{"data":{...},"meta":{"request_id":"..."}}`.

Lists: `{"data":[...],"meta":{"request_id":"...","page":1,"per_page":20,"total":42,"total_pages":3}}`.

Errors: `{"error":{"code":"unauthenticated","message":"..."},"meta":{"request_id":"..."}}`.

- `page` defaults to 1, maximum 10,000; `per_page` defaults to 20, maximum 50. Unknown fields and unsupported query parameters are rejected.
- Monetary amounts are decimal strings such as `"12.50"`; currency is explicit on each order. Avoid binary floating-point calculations in the app.
- Fixture dates and kickoff times are local civil times in `timezone` (default `Europe/London`), including daylight-saving changes. Other response timestamps have UTC offsets or `Z`.
- Fixtures/results default to the current season. Use `/seasons` and `season_id` for another season. `/fixtures` lists future/today unplayed matches; `/fixtures/{id}` can retrieve a historical fixture summary.
- League tables read the existing website cache/historical records. `updated_at` indicates freshness; `available:false` means no table is stored. This endpoint never runs a scraper.
- News respects published status and publication date; body HTML uses the existing site's sanitizer. Render it without injecting scripts/native bridges. Player bio is plain text. Player DOB and internal fixture notes are not returned.
- `/me` exposes canonical `account_id` and `person_id`. Do not interpret either as a legacy season-ticket-holder ID.
- Profile PATCH allows only display name, phone and address fields. It synchronizes those fields to the compatibility holder record transactionally. It cannot change login email, password, date of birth, activation, marketing consent, or permissions.
- Season passes are scoped to the current person, not dependents. Match tickets are visible to their assigned person or the order's buyer. Orders are scoped to the buyer's `person_id`; matching an unverified customer email does not grant access. Guest orders without explicit person links require a separate verified linking workflow before they appear here.
- `qr_payload` is the credential string supported by the existing gate scanner. Render that exact string as a QR code. It is null for revoked/inactive credentials, cancelled entitlements, and season passes outside their validity dates. Match tickets also require a paid/completed/partially refunded order. Server-side admission remains authoritative; receiving a QR payload is not an admission decision.
- Cross-member record requests return 404. Lists contain only allowed records. No payment provider IDs, raw account rows, order access links, or internal notes are serialized.

## HTTP and operational behavior

HTTPS is mandatory. The API trusts the server's HTTPS flag, not client-supplied `X-Forwarded-Proto`. Native clients do not need CORS; browser clients use the canonical origin or the explicit `MCH_API_CORS_ORIGINS` allowlist. No wildcard origins or cookie credentials are enabled.

All responses use `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. The request ID is also returned in `X-Request-ID`. Request bodies must be JSON objects and at most 16 KiB. Standard errors include 400, 401, 403, 404, 405, 413, 415, 422, 429, 500 and 503. Rate-limited responses include `Retry-After` in seconds.

Limits use atomic database counters in fixed windows:

- 300 total requests per source IP per minute.
- Login: 30 attempts/IP and 10 attempts/email per 15 minutes (successes count).
- Refresh: 60 attempts/IP per 15 minutes.

Source IP is `REMOTE_ADDR`; ensure the webserver resolves trusted reverse proxies correctly before using this behind a CDN. Do not trust arbitrary forwarding headers in application code. Large shared networks may need the limits adjusted after observing real app traffic.

One in 100 successful requests performs bounded cleanup of expired rate counters/sessions and audit events older than 90 days. Cleanup failure is logged without failing the request. No cron job has been added. Very low-traffic installations may retain expired rows longer; expiry checks do not depend on deletion.

## Configuration

Read existing `admin/.env` using its existing parser; nonempty process environment values take precedence:

- `HUB_DB_HOST`, `HUB_DB_NAME`, `HUB_DB_FALLBACK_NAME`, `HUB_DB_USER`, `HUB_DB_PASS`: same database preference/fallback as the site.
- `MCH_API_BASE_URL`: canonical HTTPS site origin, default `https://myclubhub.co.uk`. Never derived from a request Host header. Update the OpenAPI `servers` entry if this changes.
- `MCH_API_TIMEZONE`: fixture timezone, default `Europe/London`.
- `MCH_API_CORS_ORIGINS`: comma-separated exact browser origins; empty by default.

The API bootstrap deliberately avoids `admin/db.php` and `admin/config.php`; those start PHP sessions and can run schema changes. Queries reuse existing publication/scoring helpers where safe. Identity and ticket queries use the canonical schema directly with explicit ownership predicates. Keep these predicates aligned when the website's underlying business rules change.

## Installation and verification

Run from the project root:

```sh
php httpdocs/admin/lib/mobile_api/install.php
php httpdocs/admin/tests/mobile_api_test.php
```

The installer applies only `2026_09_18_001_mobile_api.php` and records it in `schema_migrations`. It creates four `mobile_api_*` tables and does not run other pending migrations. Deploy `api/index.php` and `api/.htaccess` after the migration is installed. No changes to the root router are needed. PHP extensions required: PDO MySQL, mbstring, JSON, OpenSSL; HTML sanitizing uses existing Composer dependencies.

For response-schema checks, run the PHP test with `--samples /tmp/mch-api-samples.json`, then `python3 httpdocs/admin/tests/mobile_api_contract_test.py /tmp/mch-api-samples.json` (requires Python `jsonschema`). Delete the sample file afterward; it contains synthetic tokens.

The test suite requires local MariaDB root socket access. It creates a randomly named `mch_api_test_*` database, copies table definitions only, inserts synthetic records, and drops the database in `finally`. It tests authentication, refresh/replay/concurrency, expiry, account deactivation, record ownership, publication rules, field allowlists, profile synchronization, rate limits, and contract route coverage. No real member records or passwords are copied.

To disable the API without touching business data, remove the API dispatcher/rewrite directory. The additive tables may remain for audit/recovery; do not drop them while requests are active. This feature does not change existing website authentication or scheduled jobs.
