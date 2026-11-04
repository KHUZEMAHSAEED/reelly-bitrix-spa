# Reelly API to Bitrix24 SPA Synchronization

A high-performance, standalone PHP (8.1+) CLI utility that synchronizes real estate property listings from the **Reelly API** into a **Bitrix24 Smart Process Automation (SPA)** entity on a daily schedule.

---

## Architecture & Core Design

- **Zero Full Frameworks:** Pure PHP 8.1+ using strictly allowed PSR packages (`guzzlehttp/guzzle`, `aws/aws-sdk-php`, `vlucas/phpdotenv`, `monolog/monolog`).
- **Bitrix24 as Single Source of Truth:** No local database. Listing states, sync hashes, stages, and S3 image references reside directly within the Bitrix24 SPA item.
- **First-Run Automated Provisioning:** Creates required custom SPA user fields in Bitrix24 on the first run only and caches resolved identifiers in `storage/field_map.json`. Subsequent cron runs skip all field API calls.
- **Payload State Hashing:** Evaluates normalized payload differences using SHA-1. Only modified listings trigger updates (`reelly_owned` fields only).
- **Batching & Rate-Limiting:** Executes operations using Bitrix24's `batch` method (50 commands/request) and enforces throttling to remain strictly under the Bitrix rate limit (~2 req/sec).
- **Robust Image Archival Pipeline:** Computes deterministic S3 keys (`reelly/{listing_id}/{sha1(source_url)}.jpg`), resizes/compresses via GD, uploads to AWS S3, uploads base64 file payloads to Bitrix24, and prunes orphaned image objects.
- **Concurrency & Overlap Protection:** Utilizes non-blocking OS-level file locking (`flock`).

---

## Project Structure

```
reelly-bitrix-spa/
├── sync.php              # CLI entry point
├── src/
│   ├── ReellyClient.php  # Reelly API consumer with pagination & exponential backoff
│   ├── BitrixClient.php  # Bitrix24 REST client via inbound webhook, batching & throttling
│   ├── FieldProvisioner.php  # First-run SPA custom field provisioning & cache generation
│   ├── ImageService.php  # Image downloader, GD compressor, S3 archival & Bitrix uploader
│   ├── Mapper.php        # Declarative schema transformer (fields.php + field_map.json)
│   └── SyncRunner.php    # Sync workflow orchestrator & guardrails
├── fields.php            # Declarative field definitions (single source of truth)
├── config.php            # Strongly typed environment configuration
├── .env.example          # Sample environment configuration template
├── composer.json         # Dependency definitions (PSR-4 autoloading)
├── storage/
│   ├── field_map.json    # Generated on first run (field metadata cache)
│   ├── sync.lock         # Process lock file
│   └── logs/             # Daily rotating Monolog logs (sync-YYYY-MM-DD.log)
├── tests/
│   └── test_all.php      # Automated regression test suite
└── README.md
```

---

## Webhook Permissions Required

In Bitrix24, navigate to **Applications > Webhooks > Add Inbound Webhook**:

1. **Assignee User:** Select an administrator account with administrative rights to modify CRM settings and manage SPA fields.
2. **Access Permissions (Scopes):**
   - `crm` (Required: read/write CRM items, Smart Process entities, and custom user fields).
   - `userfieldconfig` (Required for custom SPA field introspection and provisioning).

The generated Webhook URL follows this format:
```
https://your-domain.bitrix24.com/rest/1/your_webhook_token/
```

---

## Installation & Setup

### 1. Requirements
- PHP 8.1 or higher
- PHP Extensions: `curl`, `json`, `gd`, `mbstring`, `openssl`
- Composer 2.x

### 2. Install Dependencies
```bash
composer install --no-dev --optimize-autoloader
```

### 3. Configure Environment Variables
Copy `.env.example` to `.env` and fill in your credentials:
```bash
cp .env.example .env
```

| Variable | Description | Example |
| :--- | :--- | :--- |
| `REELLY_API_BASE_URL` | Base URL for Reelly API v2 | `https://api-reelly.up.railway.app/api/v2/clients` |
| `REELLY_API_KEY` | Reelly API key | `your_secret_api_key` |
| `REELLY_AUTH_HEADER` | Auth header name | `X-API-Key` |
| `REELLY_PAGE_SIZE` | Listings per page request | `20` |
| `BITRIX_WEBHOOK_URL` | Inbound Bitrix24 Webhook URL | `https://company.bitrix24.com/rest/1/xyz123/` |
| `BITRIX_SPA_ENTITY_TYPE_ID` | Smart Process entityTypeId | `1032` |
| `BITRIX_STAGE_ACTIVE` | Stage ID for active listings | `DT1032_NEW` |
| `BITRIX_STAGE_REMOVED` | Stage ID for removed/sold listings | `DT1032_FAIL` |
| `BITRIX_ASSIGNED_BY_ID` | User ID assigned to listings | `1` |
| `AWS_ACCESS_KEY_ID` | AWS IAM Access Key ID | `AKIAIOSFODNN7EXAMPLE` |
| `AWS_SECRET_ACCESS_KEY` | AWS IAM Secret Access Key | `wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY` |
| `AWS_DEFAULT_REGION` | AWS Region for S3 Bucket | `us-east-1` |
| `AWS_BUCKET` | S3 Bucket name | `reelly-property-listings` |
| `S3_PUBLIC_BASE_URL` | Optional custom CDN / CloudFront base URL | `https://cdn.yourcompany.com` |
| `MAX_IMAGES_PER_LISTING` | Maximum images synced per property | `10` |
| `IMAGE_MAX_WIDTH` | Maximum image width (GD resize) | `1600` |
| `IMAGE_JPEG_QUALITY` | JPEG compression quality | `85` |
| `REMOVAL_THRESHOLD_PERCENT` | Maximum % allowed to mark removed | `20` |

---

## First-Run Behavior & Field Provisioning

### First Run
When `sync.php` executes and `storage/field_map.json` does **not** exist:
1. Queries existing SPA fields with `userfieldconfig.list` for entity `CRM_{entityTypeId}`.
2. Diffs against `fields.php` by `bitrix_name`.
3. Creates missing user fields sequentially via `userfieldconfig.add` (including enumeration drop-down options and localized labels) with throttle pauses.
4. Queries `crm.item.fields` once to resolve Bitrix's generated field keys (`ufCrm{entityTypeId}_{NAME}`) and enum option IDs.
5. Writes the field resolution mapping and SHA-1 schema hash to `storage/field_map.json`.

### Subsequent Cron Runs
When `storage/field_map.json` exists:
- Reads the field mapping strictly from cache.
- **Zero field-related API calls** (`userfieldconfig.*`, `crm.item.fields`) are issued during regular cron runs.
- Verifies that `fields_hash` matches `sha1(fields.php)`.

---

## Schema Changes & `--refresh-fields`

If you modify `fields.php` (e.g., add new property fields or change labels), the sync halts with a clear error:
```
fields.php changed, run php sync.php --refresh-fields
```

To apply changes safely:
```bash
php sync.php --refresh-fields
```
This flag deletes `storage/field_map.json`, detects newly added fields, creates only the missing ones via `userfieldconfig.add` (non-destructively, preserving all existing Bitrix fields), rebuilds the cache, and exits immediately.

---

## Recommended First-Time Order

Follow these steps for safe, staged onboarding:

```bash
# Step 1: Configure your .env file
cp .env.example .env
nano .env

# Step 2: Dry-run simulation (writes nothing to Bitrix or S3)
php sync.php --dry-run --limit=5

# Step 3: Provision fields and test with 5 live listings
php sync.php --limit=5

# Step 4: Perform a full synchronization run
php sync.php

# Step 5: Configure the daily cron job
crontab -e
```

---

## Daily Cron Schedule

Configure a daily cron job using `flock` to guarantee single-instance execution:

```cron
0 2 * * * /usr/bin/flock -n /var/lock/reelly_sync.lock /usr/bin/php /path/to/reelly-sync/sync.php >> /path/to/reelly-sync/storage/logs/cron.log 2>&1
```

*(The internal script also enforces its own internal `flock` on `storage/sync.lock` for double protection.)*

---

## CLI Flags Reference

| Flag | Description |
| :--- | :--- |
| `--dry-run` | Simulates sync. Writes nothing to Bitrix24 or S3, and does not create `storage/field_map.json`. |
| `--limit=N` | Syncs at most `N` listings from Reelly. Disables removed listing detection to avoid false positives. |
| `--refresh-fields` | Deletes cache, creates any newly defined fields in Bitrix, updates `field_map.json`, and exits. |
| `-h`, `--help` | Prints CLI usage options and syntax. |

---

## Safety Guards & Recovery

1. **20% Removal Safety Guard:**
   If more than 20% of listings in Bitrix are absent from Reelly's response, or if Reelly returns 0 listings, the removal phase aborts with a warning log to protect against external API outages.
2. **Partial Sync Protection:**
   When running with `--limit=N`, removal processing is automatically skipped so un-fetched listings are never falsely marked removed.
3. **Resilient Image Handling:**
   If an image download or upload fails, the text fields remain safely updated, while the S3 link array remains unmodified so the next sync run retries.
4. **Duplicate Reelly ID Resolution:**
   If multiple Bitrix SPA items hold the same `REELLY_ID`, the sync logs a warning naming the IDs and binds exclusively to the lowest Bitrix ID.

---

## Troubleshooting

- **403 Forbidden on Webhook:** Verify that the webhook user has CRM administrative rights and that the CRM scope is enabled.
- **Field hash mismatch error:** Run `php sync.php --refresh-fields`.
- **Process already running:** Check `storage/sync.lock`. If a previous process terminated abruptly, ensure no stale PHP sync process remains (`ps aux | grep sync.php`), then remove `storage/sync.lock`.
- **Logs:** Review daily rotating log files in `storage/logs/sync-YYYY-MM-DD.log`.
