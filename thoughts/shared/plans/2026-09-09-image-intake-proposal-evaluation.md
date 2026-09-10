# Evaluation: Image Intake Proposal

Date: 2026-09-09  
Evaluates: `thoughts/shared/plans/2026-09-09-image-intake-proposal.md`  
Verdict: Approve the architectural direction, but define and prove the cross-system contract before implementation.  
Update 2026-09-09: the contract exists as DRAFT v1 at `featherarms-operations-digitalocean/thoughts/shared/plans/2026-09-09-image-intake-contract.md`. Items 1, 5 and 6 were resolved by research (export scope, `media_roles.py` invariants, acquisition lane as owner); items 2, 3 and 4 are decided by operator rulings in the contract (§3, §5, §7). The end-to-end trial-run gate stands (contract §10).

## Summary

The proposal draws the correct system boundary: WordPress should submit an image source and product identity, while infrastructure should fetch, validate, hash, store, and publish the image through Akeneo. This keeps S3 credentials and ingestion logic out of WordPress and preserves the coherent `_fa_media` pointer-attachment pipeline.

The design is not ready to implement. Its success depends on an undefined asynchronous contract among WordPress, the infrastructure intake, Akeneo, the export, the WordPress import, and `create-remote-attachments`. The proposal must define identity, correlation, status, failure handling, and media conflict rules before work begins in `fa-toolkit`.

## What the proposal gets right

### WordPress should not fetch the bytes

Forwarding the source URL keeps WordPress thin and avoids duplicating S3 access, hashing, placeholder detection, and URL normalization (`2026-09-09-image-intake-proposal.md:27-29,41`). Infrastructure already owns, or should own, those concerns.

This changes the REST endpoint's meaning. It will submit an intake job rather than import an image. The route and response should reflect that distinction, either through a new endpoint or a versioned contract.

### The existing `_fa_media` pipeline should remain the read path

The current feed-derived path is coherent: Akeneo exports `_fa_media`, `RemoteMedia` parses the entries, `RemoteAttachmentCreator` creates pointer attachments, and `RemoteAttachmentUrls` resolves them at render time. A new WordPress-specific attachment path would divide ownership and recreate logic that the current pipeline already handles.

### The obsolete paths should be retired

Closing #38 and #39 as superseded is better than repairing dead ACF-era scrapers. The vendor-pattern commands also appear to be retirement candidates. Removal should follow a working replacement and confirmation of current CLI usage because the proposal identifies those commands as user-facing surfaces (`2026-09-09-image-intake-proposal.md:53-59`).

## Decisions required before implementation

### 1. Prove the return path for a non-feed product

The proposal depends on the next feed run rewriting `_fa_media` (`2026-09-09-image-intake-proposal.md:43`). That works only if "not in the feeds" means absent from a vendor source feed but still present in Akeneo and included in the Akeneo-to-WordPress export.

If the product is absent from that export, its image can reach S3 and Akeneo yet remain permanently queued in WordPress. The design needs an end-to-end test with the exact kind of non-feed product covered by #28.

The full completion path is longer than the proposal makes explicit:

1. WordPress submits the request.
2. Infrastructure fetches and validates the image.
3. Infrastructure stores the object and updates Akeneo.
4. The Akeneo export runs.
5. The WordPress import rewrites `_fa_media`.
6. `create-remote-attachments` runs.

The design should name the owner and expected completion time for each scheduled or manual transition.

### 2. Add a durable request identifier

The proposed marker stores the submitted URL, role, requester, and timestamp, then clears when the SHA-256 appears in `_fa_media` (`2026-09-09-image-intake-proposal.md:43`). WordPress does not know the hash when it submits the request, and the current `_fa_media` shape contains no intake request identifier. The final URL may also differ after normalization or storage.

URL or hash matching becomes ambiguous when:

- A product has several pending images.
- A user submits the same URL twice.
- Different URLs return identical bytes.
- The requested role changes during processing.
- The product already contains the same image.

The intake should return a stable `request_id`, and that identifier should survive into Akeneo or the exported `_fa_media` entry. The request also needs explicit idempotency semantics.

### 3. Treat the marker as a job state, not a queued flag

A single `queued` value cannot distinguish slow processing from permanent failure. Use a small state model such as:

- `accepted`
- `processing`
- `completed`
- `rejected`
- `failed`
- `timed_out`

Store the request identifier, timestamps, last error, and retry information. Define which system updates each state and how WordPress learns about terminal failures.

The proposal also says the marker lets an administrator see that an image is pending. Postmeta alone provides no administrative interface, especially with ACF prohibited. The plan needs a product-screen notice, queue/status screen, or CLI inspection command.

### 4. Make the Akeneo UUID canonical

"Whatever the intake already keys on" leaves the main cross-system identifier undecided (`2026-09-09-image-intake-proposal.md:42`). A WordPress post ID has no meaning outside WordPress, and a SKU can change or collide.

Use `_fa_akeneo_uuid` as the canonical identifier and send `_sku` as a diagnostic cross-check. Reject the request if the UUID and SKU resolve to different products. This follows the current import's positive identity guarantee and avoids the duplicate-product risk documented in `src/CLI/Media/class-createremoteattachmentscommand.php:159-173`.

### 5. Define role and position conflicts

The endpoint cannot safely accept only `hero` or `gallery`. The current contract assumes one hero and ordered gallery positions (`src/Media/class-remotemedia.php:18-27`). The intake contract must answer:

- Does a new hero replace the current hero, demote it, or fail?
- Where does a new gallery image appear?
- What happens when concurrent requests select the same position?
- Does submitting duplicate bytes with a new role update the existing record?
- Which source wins when vendor media and manually submitted media conflict?

These decisions connect the intake to ops #128. The slots-versus-array decision affects intake semantics and should not remain wholly outside this proposal.

### 6. Verify that `media-index` is the correct transport

The placeholder hash file's location suggests that `media-index` may be the right owner, but it does not prove that the pipeline can serve as this intake (`2026-09-09-image-intake-proposal.md:37,44`). Before selecting it, verify that it can:

- Accept externally supplied source URLs.
- Authenticate WordPress requests.
- Resolve and validate product identity.
- Write Akeneo media and metadata.
- Return a request identifier.
- Expose job status and failure details.
- Retry safely without creating duplicates.

## Missing security requirements

Moving the fetch out of WordPress moves the remote-fetch trust boundary; it does not remove it. The infrastructure intake must protect against:

- Server-side request forgery, including redirects and DNS rebinding.
- Requests to private, loopback, link-local, and metadata-service addresses.
- Oversized responses, slow sources, and redirect loops.
- Decompression bombs and malformed images.
- Misleading content types and unsupported formats.
- Unauthorized callers and abusive request rates.

The contract should specify allowed schemes and domains, redirect policy, byte and time limits, image decoding and validation, authentication, rate limits, and audit logging.

## Tensions in the current proposal

- "Already works end to end" describes feed-derived media, not the proposed manual round trip. The infrastructure half and the reconciliation loop remain unproved (`2026-09-09-image-intake-proposal.md:33-37`).
- A postmeta marker does not make pending work visible to an administrator without a UI or inspection command.
- Choosing `media-index` from code ownership clues is a useful hypothesis, not an architectural decision.
- Retiring #38 and #39 is sound, but the replacement should work in production before those issues close.
- Choosing delayed reconciliation for the only path with a person waiting requires a visible status and a completion-time expectation.

## Recommended approval gate

Approve the direction now. Require a one-page intake contract and one end-to-end spike before changing `fa-toolkit`.

The contract should define:

1. Canonical product identity and mismatch behavior.
2. Request identity and idempotency.
3. Request and response schemas.
4. Job states, retries, timeouts, and status lookup.
5. Hero replacement and gallery ordering.
6. Correlation through Akeneo and `_fa_media`.
7. Authentication and remote-fetch security.
8. The expected time from submission to a visible WordPress attachment.

The spike should submit one image for a real product absent from the vendor feeds and prove that the correct pointer attachment appears in WordPress. Until that succeeds, the proposal describes a plausible boundary rather than a proven workflow.

## Confidence

Confidence is high in the WordPress-side diagnosis and the recommendation to retire the dead ACF-era paths. Confidence is moderate in the end-to-end design because the infrastructure intake, scheduling, status flow, and reconciliation mechanism remain assumptions.

This proposal is strong enough to choose an architectural direction. The intake contract needs another pass before implementation.
