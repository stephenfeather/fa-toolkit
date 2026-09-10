# Image Intake Proposal: non-feed images into S3 + Akeneo

Status: PROPOSAL, **superseded in part** 2026-09-09. The transport and identity sections are replaced by the contract at `featherarms-operations-digitalocean/thoughts/shared/plans/2026-09-09-image-intake-contract.md` (see `2026-09-09-image-intake-contract-pointer.md`). The direction (WordPress forwards a URL; infrastructure fetches) stands. Nothing here is implemented.

Scope: fa-toolkit issues #28, #29, #38, #39. Inventory this argues from: `thoughts/shared/agents/scout/2026-09-09-image-import-inventory.md`.

## Corrections to the issue framing

- "We don't use WP media" (#29) is not a design constraint. It was Stephen rebuffing an agent's claim that the site used a media-offload plugin, which it never did. Attachment posts and the uploads directory are not ruled out.
- The #29 constraints that do hold: no Cloudflare Images for featherarms.com; Media Cloud is not the base (media-labs replaces it if that capability is ever needed); no ACF, at all.
- "Save fa-toolkit for last" (#28, #29) still applies. This document is design input, not a start on implementation.

## What WordPress has today

Three image sources exist in the plugin. Only the first matches the current import.

| Source | Entry point | What it does now | State |
|---|---|---|---|
| Feed-derived | `wp fa:media create-remote-attachments` → `RemoteAttachmentCreator` → `RemoteMedia` → `RemoteAttachmentUrls` | Reads `_fa_media` JSON (role, title, sha256, position, URL) written by the import; creates pointer attachments with no file; dedups on sha256; rewrites URLs to ImageKit on render | Current, tested, coherent |
| Vendor URL patterns | `wp fa:media fetch-import-product-image`, `wp fa:media scrape-product-media`, `wp fa:media export-draft-product-image-sources` | Build or scrape a Davidsons/CSSI image URL from dealer + SKU, download, sideload into uploads | ACF-dependent; scrape path dead behind `die()` (#38); placeholder-hash table and URL scrub duplicated across two files |
| Browser-forwarded URL | `POST /wp-json/fa-toolkit/v1/import-media-image` | Downloads a URL into uploads, hashes it, attaches it to product 0 (no product) | Works, but discards the product; this is the "method for products not in the feeds" #28 asks about. `scrape_product_data` (#39) is a labelled prototype that fatals on the first image it finds |

Nothing in WordPress writes toward Akeneo or S3 today.

## The four questions that define the intake

1. **Who fetches the bytes?**
   - (a) WordPress forwards only the source URL; the S3/Akeneo side fetches. Keeps WP thin and reuses the feed pipeline's fetch, scrub, and placeholder-hash logic.
   - (b) WordPress downloads and uploads the object. WP then needs S3 credentials and must own hashing and placeholder detection, which it currently duplicates in two files.

2. **What identifies the product on the way in?** SKU, `_fa_akeneo_uuid`, or WP post id. And who assigns role (hero vs gallery) and position: the person forwarding, or a rule on the Akeneo side.

3. **How does the image come back to WordPress?**
   - (a) Wait for the next feed run to rewrite `_fa_media`. Simpler; already works end to end.
   - (b) The intake responds synchronously with the final URL and sha256 so WP can create the pointer attachment immediately. This is what a person browsing and forwarding expects to see.

4. **What is the transport?** An Akeneo API media upload, a presigned S3 upload plus a manifest row, or a queue that the featherarms-infrastructure media-index already consumes. The placeholder-hash file already lives in that repo, which suggests its pipeline is the natural intake.

## Proposed shape (for Stephen to knock down)

- WordPress forwards **URL + product identifier + intended role** to an infrastructure-side intake. It does not download bytes. (Q1 = a.)
- Product identifier is whatever the intake already keys on; from WP's side `_fa_akeneo_uuid` and `_sku` are both available on the product. (Q2: intake's choice; role chosen by the forwarder, defaulting to gallery.)
- The image returns through `_fa_media` on the next feed run. (Q3 = a.) The REST endpoint records a **queued** marker on the product (a postmeta entry with the forwarded URL, role, requester, timestamp) so the admin can see it is pending and so the next `create-remote-attachments` run can clear the marker when the sha256 appears in `_fa_media`.
- Transport is the infrastructure media-index pipeline. (Q4: to be confirmed with that repo's owner.)

## What this does to the four issues

- **#28** (non-feed image capability): preserved, as the REST endpoint. Its destination changes from `wp_upload_bits` to the intake, and it stops discarding the product id.
- **#29** (route CLI/Chrome ingest to S3 + Akeneo): the REST endpoint is the Chrome path. The CLI path is the two vendor-pattern commands; they become either thin forwarders of a vendor-pattern URL to the same intake, or they retire because the feed pipeline already knows the vendor CDN patterns. Vendor slugs now come from `_fa_vendor`, not ACF.
- **#38** (`scrape-product-media` dead `die()`): close as superseded, not fixed. Un-dying it resurrects a scraper that writes files into uploads and duplicates what the intake will own.
- **#39** (`scrape_product_data` undefined method): same. The command is prototype scaffolding; its useful half (scrape a page for image URLs) belongs on the intake side if anywhere.

## Sequencing when the intake exists

1. REST forwarder first. It is the only non-feed path with a user behind it.
2. Retire `fetch-import-product-image`, `scrape-product-media`, `export-draft-product-image-sources`, `scrape_product_data`. Close #38 and #39 as superseded. Remove their entries from `DEFERRED_TO_OVERHAUL` in `tests/Plugin/AcfCallSitesTest.php` as each goes.
3. Consolidate the placeholder-hash table and URL scrub into one place if anything still needs them in WordPress after step 2.

Each step changes a CLI surface that is in active use (#28, #29 caution). Each is a user-facing change and gets called out as one.

## Open, outside this repo

- ops #128: slots vs JSON array for how both image sources write media. Not decided; this proposal assumes the current `_fa_media` JSON shape and does not resolve it.
- Whether the intake is synchronous enough to answer Q3 = b later without changing the WP side beyond the queued-marker handling.
