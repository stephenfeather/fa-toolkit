# Rebuttal: Image-Intake Contract Plan (`purring-sky.md`)

Date: 2026-09-09  
Rebuts: `~/.claude/plans/proposal-thoughts-shared-plans-2026-09-0-purring-sky.md`  
References: 
- `thoughts/shared/plans/2026-09-09-image-intake-proposal.md`
- `thoughts/shared/plans/2026-09-09-image-intake-proposal-evaluation.md`

---

## Executive Summary

While the plan in `purring-sky.md` performs valuable codebase archaeology—especially by locating shipped invariants in `media_roles.py` and identifying SQLite `NULL` deduplication pitfalls—its primary thesis overreaches. In attempting to "delete" the evaluation's questions rather than resolve them, it introduces **four critical architectural contradictions** and pushes internal infrastructure burdens onto WordPress.

---

## 1. The Identity Domain Fallacy (Plan §2 & Finding 6)

* **The Plan’s Claim:** The evaluation picked the "wrong" domain by making `_fa_akeneo_uuid` canonical. The plan asserts that the submission *must* carry both `akeneo_uuid` **and** `akeneo_identifier` (`fa_g_<gtin14>` / `fa_k_<hash>`), and that mismatch between any two is an outright rejection.
* **The Reality:** **WordPress does not possess `akeneo_identifier`.**
  * In WooCommerce, products store `_sku` and `_fa_akeneo_uuid` (as documented in `src/CLI/Media/class-createremoteattachmentscommand.php:159-173`). WordPress has no record of `fa_g_...` or `fa_k_...`.
  * If the intake contract rejects any submission lacking `akeneo_identifier`, **every submission from WordPress or the Chrome forwarder will fail on day one.**
* **The Rebuttal:** Demanding that a client supply an internal infrastructure database key (`akeneo_identifier`) breaks service encapsulation. The evaluation was correct: `_fa_akeneo_uuid` is the canonical identifier at the boundary, cross-checked with `_sku`. The intake pipeline (which owns `uuid_ledger.csv` and accesses Akeneo) is the only component equipped to resolve `_fa_akeneo_uuid -> akeneo_identifier`.

---

## 2. The Synchronous `request_id` Paradox (Plan §3 vs. §6 Option A)

* **The Plan’s Claim:** Section 3 mandates: *"A server-assigned `request_id` returned synchronously."* Meanwhile, Section 6 presents Option (a) (*"Pull queue, no new service... a new `./fa media:ingest-queue` task pulls from WordPress on your next catalogue run"*) as an open option.
* **The Contradiction:** Under Option (a), **there is no server running to return a synchronous ID.** 
  * If WordPress writes the submission to postmeta and waits days for an operator to run `./fa media:ingest-queue`, who issues the synchronous `request_id`?
  * The plan claims in line 71 that Sections 3, 4, and 8 hold under all three transport options, but Section 3's synchronous requirement structurally disqualifies Option (a).
* **The Rebuttal:** The contract must make the primary correlation token a **client-generated UUID** (`submission_uuid` / `idempotency_key` issued by WordPress). This decouples request correlation from transport timing, functioning identically under a batch pull queue (Option a) or an HTTP daemon (Option b).

---

## 3. The Fiction of Option (b) Behind a Local SQLite Database (Plan §4 & §6)

* **The Plan’s Claim:** Option (b) proposes an authenticated HTTP receiver standing up the "Akeneo-aware uploader service" to accept submissions and write to `media-index.db`.
* **The Reality:** 
  * The plan's own Finding 4 emphasizes that `media-index.db` is an **offline, gitignored, single-writer SQLite file living in the operator's working tree**, executed with `--execute` manually during maintenance windows.
  * The 2026-09-08 ADR amendment states explicitly: *"`media-index.db` is not reachable from the container the attachment creator runs in… and should not be."*
* **The Rebuttal:** If `media-index.db` lives on an operator's local development machine, an HTTP receiver deployed on a server cannot write to it without either:
  1. Migrating `media-index.db` to a networked database (PostgreSQL), or
  2. Running a persistent service on the operator's workstation and tunneling to it.
  Presenting Option (b) as a simple transport choice without scoping the database migration glosses over a severe topological barrier. Option (a) (asynchronous pull from WordPress) is the only transport compatible with an offline SQLite workflow.

---

## 4. Premature Dismissal of the Non-Feed Product Return Path (Plan Finding 3 vs. Evaluation Item 1)

* **The Plan’s Claim:** Evaluation item 1 is moot because `woo_export2` runs `enabled ALL, completeness ALL` without category filters, proving non-feed products will be exported.
* **The Blind Spot:** The plan conflates *export filter flags* with *catalogue state*.
  * What happens when an operator forwards an image for a product that exists in WordPress but is *not yet complete or enabled* in Akeneo? Or a product that was created directly in WooCommerce?
  * If a product is disabled or incomplete in Akeneo, does `woo_export2` still emit it?
  * What happens if the intake script receives an image for a product whose `akeneo_uuid` is missing from `uuid_ledger.csv`?
* **The Rebuttal:** The evaluation's call for an end-to-end spike was not asking whether `export_akeneo_products.py` checks categories; it was asking whether an edge-case, non-feed product actually makes it through Akeneo's completeness/enablement checks back to a `_thumbnail_id` in WooCommerce. That remains an open question that code reading alone does not settle.

---

## 5. Conflating Internal ETL Row Statuses with Client Job States (Plan Finding 7 & §4)

* **The Plan’s Claim:** Discards the evaluation's 6 job states (`accepted`, `processing`, `completed`, `rejected`, `failed`, `timed_out`) and advocates reusing `media_refs.status` (`pending | written | overflow | orphan | skipped_placeholder | deferred | corrupt`) and `media_writes`.
* **The Rebuttal:**
  * `media_refs.status` is an **internal ETL state enum** designed for a batch migration script re-indexing legacy attachment rows. States like `overflow` (exceeded slot limits) or `orphan` (no matching post ID) are internal database artifacts.
  * A client (WordPress admin or Chrome extension) polling or inspecting a submission does not need database diagnostic codes; it needs workflow lifecycle states (`queued`, `fetching`, `stored_in_akeneo`, `failed`, `complete`).
  * Exposing low-level SQLite column enums as the external contract leaks internal implementation details and tightly couples WordPress to ops script refactorings.

---

## 6. The 7-Step Latency Reality Destroys the "API Intake" Premise (Plan Finding 8)

* **The Plan’s Finding:** The complete loop involves **seven steps, at least four manual, none on a schedule**, dependent on maintenance windows.
* **The Logical Conclusion:** If an image submitted by a user waiting at a browser takes days or weeks to appear in WordPress, the system is **not an uploader service**; it is an **asynchronous review queue**.
* **The Rebuttal:** Building an elaborate submit-and-poll REST API with synchronous receipt tokens is an over-engineered mismatch for a process that ends in a human operator typing `./fa media:assign-slots --execute` during a scheduled maintenance window.

---

## Recommended Adjustments for the Contract

1. **Identity Boundary:** WordPress submits `(akeneo_uuid, sku)`. The intake script resolves `akeneo_uuid -> akeneo_identifier` via `uuid_ledger.csv`.
2. **Correlation:** Use a client-generated UUID (`submission_uuid`) rather than requiring a synchronous server-assigned ID.
3. **Transport Realism:** Acknowledge that Option (a) (WordPress stores queued submissions in postmeta, drained by `./fa media:ingest-queue`) is the **only** transport viable under the current offline SQLite constraints.
4. **State Separation:** Keep `media_refs.status` for internal ETL processing, but define a lightweight client-facing status mapping for WordPress inspection.
