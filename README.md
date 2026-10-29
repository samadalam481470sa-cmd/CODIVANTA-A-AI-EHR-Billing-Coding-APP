# ✧ CodiVanta AI

**Clinical Revenue Intelligence** · PHP 8.4 · EHR Workflow · FY2027 ICD-10-CM Coding Assistance · Explainable Claims Auditing

![CodiVanta AI dashboard](docs/screenshots/codivanta-dashboard.jpg)

[![Clinical Engineering Checks](https://github.com/samadalam481470sa-cmd/CODIVANTA-A-AI-EHR-Billing-Coding-APP/actions/workflows/ci.yml/badge.svg)](https://github.com/samadalam481470sa-cmd/CODIVANTA-A-AI-EHR-Billing-Coding-APP/actions/workflows/ci.yml)

> **RESEARCH/PORTFOLIO DEMO. Synthetic data only. No clinical use, PHI, payer submission or real financial settlement.** The project is a functional workflow demonstration—not a HIPAA-certified EHR, an autonomous diagnostic system, or a compliant billing platform.

## Product walkthrough

![Medical coding workspace](docs/screenshots/codivanta-coding.jpg)

![Claims scrub and audit workflow](docs/screenshots/codivanta-claims.jpg)

| Console | Real implemented workflow |
|---|---|
| **Overview** | Live patient, claim, denial and receivables totals; operational status and audit integrity |
| **Patients** | Create synthetic records, browse seeded examples, view registry, generate read-only Patient-shaped FHIR JSON |
| **Encounters** | Register encounters, save notes, add catalog-verified FY2027 diagnosis codes, approve review |
| **Coding assistant** | Suggest *only* codes in an officially imported catalog, show source sentence and heuristic score, require approval |
| **Claims** | Create priced charge, scrub, record simulated submission, denial, appeal and payment |
| **Audit** | Explain deterministic blocking and warning findings; prohibit blocked claim submission |
| **ICD explorer** | Search imported official FY2027 codes/descriptions and billable flag |
| **Event log** | Record state-changing operations and verify hash links |

## Run locally (PHP 8.4, no Composer or Node required)

```bash
php bin/seed.php
php -S 127.0.0.1:8080 -t public public/router.php
# open http://127.0.0.1:8080
```

### Demo login

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `DemoAdmin2027!` |
| Clinical coder | `coder` | `DemoCoder2027!` |
| Billing specialist | `biller` | `DemoBiller2027!` |

These are **intentionally hardcoded demo identities**. Do not make this service publicly accessible. Setting `CODIVANTA_DEMO_MODE=0` disables login rather than implying that production authentication exists.

### Docker (host-local only)

```bash
docker compose up --build -d
# visit http://127.0.0.1:8080
# The Docker demo seeds fictional records and six illustrative reference codes automatically on first boot.
```

## Load authoritative FY2027 ICD-10-CM codes

The assistant does **not** ship fake, outdated or unverified code catalogs. The sample seed installs **six illustrative demo reference codes**, so the complete simulated encounter-and-claim workflow can be exercised immediately. They are **not** represented as the authoritative complete FY2027 catalog. Without a seed or official import, lookup is empty and diagnosis assignment is blocked. Use the official October 1, 2026 release for services during **October 1, 2026–September 30, 2027**.

1. Visit [CMS ICD-10 code files](https://www.cms.gov/medicare/coding-billing/ICD-10-codes) or the [CDC NCHS FY2027 directory](https://ftp.cdc.gov/pub/Health_Statistics/NCHS/Publications/ICD10CM/2027/).
2. Download `icd10cm-code-descriptions-2027.zip` and extract `icd10cm-order-2027.txt`.
3. Import:

```bash
php bin/import_codes.php /absolute/path/to/icd10cm-order-2027.txt
```

The importer reads the official 5-digit ordinal/code/billable/description structure, replaces the limited demo fixture, requires a plausible complete release (50,000+ entries), records a SHA-256 and import metadata, and activates FY2027 lookup and code assignment. It does not implement all coding instructions in the official Tabular List or Guidelines. Reference browsing: [ICD10Data](https://www.icd10data.com/ICD10CM/Codes). **Do not treat third-party search pages as authoritative bulk import files.**

## What the “AI” does (and does not do)

An explainable *deterministic coding assistant* matches terms in affirmative notes to descriptions in the imported official FY2027 catalog, discards negated/suspected statements, and returns a sentence evidence span with a heuristic match score. It **never guesses codes** from an LLM and never makes final decisions. It is *not* a trained generative model or certified medical coding engine; scores are **not clinically calibrated probabilities**. An **optional OpenAI-compatible model reviewer is implemented** in `src/AIModel.php` and remains OFF by default. When enabled, it may reorder only preselected catalog-grounded codes, and evidence must be an exact note substring. Invalid/unsupported model responses fall back to deterministic suggestions. This is not HIPAA/BAA approved. **Never enable it with real patient data.**

```bash
export CODIVANTA_DEMO_MODE=1
export CODIVANTA_ALLOW_REMOTE_AI=1
export CODIVANTA_OPENAI_API_KEY='your-real-secret-here'
# optional CODIVANTA_OPENAI_MODEL=gpt-4.1-mini
php -S 127.0.0.1:8080 -t public public/router.php
```

## Tests

```bash
php tests/run.php
find src public bin tests -name '*.php' -exec php -l {} \;
node --check public/assets/app.js
```

Tests exercise login/RBAC/CSRF, patient/encounter creation, documentation, catalog rejection, audit findings, guarded claim submission, state changes, denial, appeal, payment bounds, FHIR-shaped JSON, audit hashes and date validity. GitHub Actions also runs Docker build and an HTTP smoke check.

## Architecture and risk

- **Web frontend** `public/app.html`, `public/assets/*` — responsive operations dashboard and real API-linked buttons.
- **API** `public/index.php`, `src/App.php` — PHP routing, sessions, authorization and workflows.
- **Coding** `src/Clinical.php` — FY2027 membership + billable/effective-date checks and explainable suggestion service.
- **Claims audit** `src/Audit.php` — deterministic scrubbing rules and severity-coded explanations.
- **Persistence** `src/Store.php` — file-locked, atomic JSON store for single-node **synthetic demo only**.
- **Imports** `bin/import_codes.php` — official CDC FY2027 order-file loader.
- **Seed** `bin/seed.php` — synthetic fictional patient and encounter records.
- **CI** `.github/workflows/ci.yml` — syntax, unit/integration coverage, HTTP smoke, container build.

Read [Architecture](docs/ARCHITECTURE.md) and [Security / Production Readiness](docs/SECURITY_AND_READINESS.md) before making healthcare deployment claims.

### Developer roadmap

Upgrade the demo to Laravel 12+, PostgreSQL tenant isolation, OIDC/MFA, complete FY2027 tabular rules, contract-licensed CPT/HCPCS and NCCI validation, externally anchored immutable audit logs, tenant-safe FHIR R4/US Core services, 837/835 clearinghouse adapters, independent security assessment, and medically reviewed AI evaluations.

---

Designed to demonstrate hands-on healthcare software engineering, explainable auditing, security-conscious API design, and testable revenue-cycle state machines—not to replace human coders or clinicians.
