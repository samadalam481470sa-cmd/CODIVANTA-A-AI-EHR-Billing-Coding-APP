# Security and production readiness

**Status: educational portfolio / synthetic-data demonstration. NOT suitable for real patient records or medical billing.**

## Implemented controls

- No PHI seeded; clear synthetic-data warning on every screen.
- HTTP-only, SameSite Strict session cookies; Secure flag whenever served over HTTPS.
- CSRF token required on all authenticated write methods.
- Explicit role checks; coding and billing permissions separated.
- Strict input length checks, amount range validation, response headers including CSP, and HTML escaping.
- Audit actions capture actor, type, object reference and SHA-256 hash chain; no clinical narrative in audit details.
- No client-accessible `data/` directory; web root is `public/` only.
- Fails closed when `CODIVANTA_DEMO_MODE=0` — **does not** silently enable production mode.
- Localhost-only host port in Docker Compose.

## Known blockers before any real healthcare deployment

- Hardcoded **publicly documented demo credentials**, no real OIDC/MFA/password management.
- Single-machine JSON store lacks database-grade security, scalability, encryption at rest and backup/restore controls.
- No HIPAA risk analysis, BAAs, authorization matrices, tenancy isolation, PHI leak prevention or penetration testing.
- No complete official ICD-10-CM Tabular List semantics (Excludes1/2, Code First, Use Additional, etc.) and no clinical coding certification.
- No licensed CPT/HCPCS validation, payer medical-necessity policies, NCCI edits or EDI transactions.
- Simplified FHIR projection only; no normative implementation guide compliance.
- Hash-linked audit log is not externally anchored and is **not tamper-proof**.
- An optional generative model adapter exists, but is OFF unless explicitly configured and not clinically validated. The default assistant is explainable rule-based text matching; **not a clinical decision support system**.
- PHP built-in server used for **local demo**, not a production web server.

## Regulatory guardrails

Always require appropriately qualified human reviewers for diagnosis/code decisions and comply with payer, professional, and regulatory guidance. Do not process protected health information or bill actual payers with this application.
