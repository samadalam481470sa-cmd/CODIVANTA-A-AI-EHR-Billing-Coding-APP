# Architecture & threat model

## Actual implemented architecture

- **Frontend:** native JavaScript, accessible responsive HTML/CSS, no SaaS dependency. All state-changing visible actions use PHP endpoints with CSRF protection; no dead UI-only management controls.
- **Backend:** PHP 8.4 request dispatcher, RBAC (`admin`, `coder`, `biller`), form and JSON validation, session authentication, separate clinical, audit and storage services.
- **Storage:** `Store` adapter keeps the entire **synthetic-only** dataset in `data/state.json` with `flock` and atomic file replacement. This is an intentionally single-machine demo backend, not a production database.
- **Audit:** previous-hash linked event log with verification endpoint. Hashes show tampering but can be rewritten by an attacker with file system write access; not externally anchored immutability.
- **Coding:** FY2027 fixed-width order-file importer reads official CDC NCHS file, checks plausible complete code count and SHA-256 provenance. The runtime checks code presence, billability and effective date. Six example codes are provided *only* as an explicitly marked demo fixture until the official FY2027 catalog is imported.
- **Assistant:** deterministic keyword matching in affirmative clinical sentences against codes in the imported catalog, returning evidence text. An optional, explicitly configured external LLM reviewer exists; it receives synthetic notes only and can return only evidence-grounded suggestions validated against the deterministic candidate set. The default mode remains rule-based. Neither mode implements all guideline semantics. These limits are deliberate to avoid ungrounded clinical coding claims.
- **Claims:** state machine for creation, scrub, simulated submission, denial, appeal and payments. Amounts use integer US cents. No real EDI/clearinghouse transport is present.
- **FHIR:** minimal read-only Patient-shaped JSON projection for testing; not conformance-certified HL7 FHIR server.

## Workflow

Synthetic Patient -> Encounter -> Clinical Note -> Catalog-verified Code -> Human Review -> Claim -> Audit Scrub -> Simulated Submit -> Deny/Appeal/Payment.

## Boundaries

No external model or inference service, real EHR integration, licensed CPT master file, claim eligibility verification, payer-contract modeling, or real payment processor. The sample 5-character procedure validation does not attest that a CPT/HCPCS code is valid, payable or medically necessary.

## Production modernization roadmap

Replace the demo store with PostgreSQL row-level authorization, tenant-isolated storage, transaction-scoped audit outbox, immutable external log anchoring, clinical terminology service with full exclusions/sequencing rules, human adjudication tasks, OIDC/MFA, HSM-managed encryption, PHI detection and redaction, end-to-end authorization testing, external FHIR conformance, managed secrets, disaster recovery, observability, BAA-vetted cloud environment, payer policy licensing, and a licensed clearinghouse integration. Only then assess HIPAA deployment readiness with qualified professionals.
