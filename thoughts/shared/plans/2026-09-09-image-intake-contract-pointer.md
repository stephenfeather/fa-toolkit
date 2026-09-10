# Image-intake contract — pointer

The contract for browser-submitted product images lives in the ops repo, beside the ADR and schema it inherits from:

`featherarms-operations-digitalocean/thoughts/shared/plans/2026-09-09-image-intake-contract.md`

Status: DRAFT v1 for operator approval, 2026-09-09. Rulings R1–R11 given in session 2026-09-09.

## What fa-toolkit owns under that contract

Nothing in `src/` or `tests/` changes until the contract is approved and the ops-side pieces exist ("save fa-toolkit for last"). When it does, the plugin owns:

- table `wp_fa_media_intake` (dedicated, `dbDelta`), unique `(caller_id, idempotency_key)`, lease and version columns;
- REST routes under `/wp-json/fa-toolkit/v1/media-intake-requests` — submit (`upload_files`), status, list, claim, update, reconcile (custom cap `fa_media_intake_pull`);
- WP-CLI mirror `wp fa:media intake submit|status|list|claim|update|reconcile|replay`, same fields and output, one shared service class;
- a service user `fa-intake-puller` (subscriber + `fa_media_intake_pull`) authenticated by Application Password;
- the existing `wp fa:media create-remote-attachments` as the final step, unchanged.

## Decisions that bind this repo

| Decision | Value |
|---|---|
| Identity sent | `akeneo_uuid` (from `_fa_akeneo_uuid`, required), `asserted_akeneo_identifier` (from `_fa_akeneo_sku`), `woo_sku` (from `_sku`, diagnostic) |
| Request id | minted by WordPress, UUID v4, returned synchronously with HTTP 202 |
| Idempotency | scope `(caller_id, idempotency_key)`; replay ⇒ 200 with original id; different body ⇒ 409 `idempotency_conflict` |
| Public states | `queued, processing, waiting_for_catalogue, waiting_for_wordpress, completed, rejected, failed` |
| v1 scope | images only, one URL per request, `role_intent ∈ {auto, hero, gallery}` |
| Completion | unbounded; `status_url` is the pending surface |

## Related documents in this repo

- `2026-09-09-image-intake-proposal.md` — superseded in part (transport, identity) by the contract.
- `2026-09-09-image-intake-proposal-evaluation.md` — its six gate items are resolved by the contract; the trial-run gate stands.
- `2026-09-09-image-intake-contract-plan-rebuttal.md` — adjudicated; item 1's premise was wrong (WordPress does carry `_fa_akeneo_sku`), items 2–6 upheld.
