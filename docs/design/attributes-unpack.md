# Unpacking `_fa_attributes` after the SSI content import

Design note for [#109](https://github.com/stephenfeather/fa-toolkit/issues/109). No plugin code accompanies it.

Status: proposed, 2026-09-16.

## The question

The SSI content import writes `_fa_attributes` postmeta on each product: one compact JSON object, normalized keys sorted, values string/number/bool/null/array, no nested objects, omitted when empty (upstream guarantee: inventory-feeds-management#221). SSI writes postmeta by direct SQL, so no WordPress hook fires per product. How does fa-toolkit turn that cell into something the storefront can show?

Operator ruling that bounds the answer (2026-09-16): "attributes in woo are more often freeform/open_set vs terms which are 'fixed'". The open-set vendor keys fit WooCommerce local product attributes. Curated vocabularies (caliber, action, brand, the `pa_*` taxonomies the content import already writes) stay as terms. The JSON is not routed into taxonomies by default.

## What the data looks like

Measured read-only on the Sep-12 canonical artifact (`Transform/output/canonical_products.ndjson` in inventory-feeds-management), which is the source of the `attributes` dict. `_fa_attributes` itself is not yet in any content CSV on disk; the newest one (`refresh-content-20260913.csv`) predates the ruling.

| Measure | Value |
|---|---|
| Products | 73,336 |
| Products with a non-empty dict | 49,095 |
| Distinct keys | 327 (matches #109) |
| Key/value pairs | 313,315 |
| Keys per product | median 5, p90 12, max 25 |
| Cell size, compact JSON | median 150 B, p90 338 B, max 806 B |
| Value types seen | string only: no number, bool, null or array today |
| Keys whose values are `Y`/`N`-style strings | 101 (`checkering` 5,463, `recoil_pad` 5,197, `swivel_studs` 3,526) |
| Values containing `\|` | 0 |
| Long tail | 148 keys on 100 products or fewer; 28 on 10 or fewer |

Top keys: `dimension` 30,479, `color` 22,123, `other_features` 20,547, `rsr_subcategory` 20,262, `material` 14,314.

Three things in that list drive the design:

1. **Some keys duplicate a curated channel.** The content CSV already writes 16 `pa_*` taxonomies. Seven have a vendor key of the same name: `size` 4,553, `bullet_type` 2,434, `frame_material` 1,953, `reticle` 819, `tube_diameter` 476, `shoe_size` 332, `finish` 54. Unpacked blindly, those products show "Bullet Type" twice in one table.
2. **Some keys are not customer-facing.** `rsr_subcategory`, `rsr_description` 6,284, `boxes_per_case` 3,778, `units_per_case` 2,597, `packs_per_case`, `cans_per_case`.
3. **The contract is wider than the data.** Arrays and booleans are allowed and absent. The rules below are specified for the contract, and tested with synthetic cells, because nothing real exercises them yet.

Upstream quality that this plugin does not fix: float-mangled numerics (`boxes_per_case: "25.0000"`, `overall_length_in_inches: "43.7500"`), column-collision keys (`type_color`, `size_finish`, `metal_color: "N"`), truncated text (`other_features`). Values pass through verbatim; repairs belong in ifm#221.

## Recommendation

**Unpack the open set into WooCommerce local (non-taxonomy) product attributes, from a marker-driven listener on `superspeedyimports_after_import_stages`, with a small denylist and automatic suppression of keys a `pa_*` attribute already covers.**

### Target: local product attributes in `_product_attributes`

Each unpacked key becomes one entry:

```php
'barrel_finish' => array(
    'name'         => 'Barrel Finish',
    'value'        => 'Matte Blued',
    'position'     => 7,
    'is_visible'   => 1,
    'is_variation' => 0,
    'is_taxonomy'  => 0,
)
```

Why this target works with SSI rather than against it (verified in SSI's source on local staging, `stages/woocommerce/attribute-engine-wpdb.php:257-262`): when SSI rebuilds `_product_attributes` it rewrites the `pa_*` entries and carries every non-taxonomy entry through untouched. Entries fa-toolkit writes survive the next weekly import; nothing fights over the row.

The same fact means nothing but fa-toolkit will ever remove them, so ownership is explicit. A sidecar meta `_fa_attributes_unpacked` holds the list of entry slugs the last pass wrote. A pass removes exactly those, then writes the current set. Local attributes the operator added by hand, and anything SSI's own custom-attributes feature wrote, are never in the sidecar and are never touched. If an unpacked slug collides with an entry fa-toolkit does not own, the foreign entry wins and the summary counts a `collision`.

Writes go through `update_post_meta()` on the one row, not `WC_Product::save()`. The product CRUD save fires the full hook chain per product and is an order of magnitude slower across ~49k products. The known exposure is WooCommerce's product attributes lookup table, and it is not affected: `LookupDataStore::get_attribute_taxonomies()` skips any attribute without a taxonomy id ("Custom product attribute, not suitable for attribute-based filtering", WooCommerce 11.1.0 on local staging, `LookupDataStore.php:592-595`), so a local-attribute write leaves the table correct. The runner invalidates the per-product cache after each write.

Simple products and variable parents get attributes; `product_variation` posts are skipped, as SSI's own engine does.

### Trigger: a second after-import listener, same seam as media

`AfterImportAttributes` on `superspeedyimports_after_import_stages`, priority 20 (after `AfterImportMediaAttachments` at 10), with the media listener's shape: filterable enable switch, batch size, drain loop, `max_products` / `max_seconds` budgets, a JSON summary line to WP-CLI or the error log, and a `fa_toolkit_after_import_attributes_run` action carrying the summary.

Per the #24 ruling, the product loop lives in a shared runner, and `wp fa:attributes unpack [--dry-run] [--product=<id>] [--force]` drives the same runner. Neither caller carries logic the other lacks.

The hook fires for the price import too. That import does not map `_fa_attributes`, so the selection finds nothing and the run costs one query.

### Idempotency and reruns

The pass is a pure function of three inputs: the cell, the rules, and the product's existing `_product_attributes` row. The third matters as much as the first. The `pa_*` shadow reads which taxonomy entries the product carries, and the collision rule reads which foreign local entries it carries; SSI changes the former on any import, and an admin edit changes the latter, without the cell moving. A marker over the cell alone would leave a product "applied" while a newly arrived `pa_bullet-type` sits beside its unpacked twin, or while a slug stays suppressed by a foreign entry that was deleted last week (PR #111 review).

So there are two markers, one per mutable input, and both are compared in MySQL:

- `_fa_attributes_applied_sha256` holds `sha256( cell . '|' . ruleset_hash )`. `ruleset_hash` covers the denylist, the label map and an unpacker version constant.
- `_fa_attributes_row_sha256` holds the sha256 of the `_product_attributes` row exactly as the pass left it (of `''` when the product has no row).

```sql
WHERE m.meta_key = '_fa_attributes' AND m.meta_value <> ''
AND (
    NOT EXISTS ( ... a.meta_key = '_fa_attributes_applied_sha256'
                 AND a.meta_value = SHA2( CONCAT( m.meta_value, '|', %s ), 256 ) )
    OR NOT EXISTS ( ... r.meta_key = '_fa_attributes_row_sha256'
                 AND r.meta_value = SHA2( COALESCE( pa.meta_value, '' ), 256 ) )
)
```

`pa` is the product's `_product_attributes` row, left-joined. Anything that touches that row after the pass (SSI adding a term, an admin removing a local attribute, another plugin) makes the product selectable again. A collision is therefore not a terminal state: the product is marked applied for the row as it stood, and is re-unpacked when the row changes.

- Unchanged cell, unchanged rules, untouched row: not selected. A weekly rerun of an unchanged catalogue costs one query.
- A key vanishes from the JSON: the cell hash changes, the product is selected, the pass removes every slug in the sidecar and writes the current set. The vanished key is gone.
- The denylist or a label changes: `ruleset_hash` changes, every product re-unpacks once. No operator step.
- The row changes under us (new `pa_*` term, foreign entry added or removed): the row hash no longer matches, the product is selected, shadows and collisions are recomputed.
- The cell becomes empty: a second selection arm picks products with a non-empty sidecar and an absent or empty cell, and clears them.
- Invalid JSON, or a top-level value that is not an object: the product keeps its previous attributes, gets neither marker, and is counted `invalid`. Same rule as media: a failure stays selectable.
- A pass whose output equals the row already stored writes the markers only. Positions are assigned in key order after the last foreign entry, and the JSON arrives key-sorted, so the same inputs give the same bytes.

The row marker has one cost that is not yet known. SSI rewrites `_product_attributes` for every product whose import maps `pa_*` columns. If that rewrite is byte-identical when nothing changed (it preserves entry order, `position` and `is_visible`: `attribute-engine-wpdb.php:157-165, 301-313`), the steady state stays at one query. If it is not, every product re-unpacks on every content import: still correct and convergent, but the first-pass cost recurs weekly. The staging measurement settles which, before a budget default is chosen.

**What an empty cell does (answered 2026-09-16, from infra-dev's I-01 report of 2026-09-13).** A blank CSV cell writes `''` over the existing meta; only a column absent from the CSV leaves existing meta untouched. SSI's update is `SET pm.meta_value = import.<col> WHERE import.<col> IS NOT NULL` (`stages/core/update-postmeta.php:64-69`); that a blank cell loads as `''` and not `NULL` is I-01's finding and was not re-verified for this note. So `''` is a positive "the vendor sent nothing" signal and the second selection arm clears on it. No `{}` sentinel is needed from the importer.

The remaining stale case is an absent column, which would freeze every product's cell at last week's value. That is not detectable from inside WordPress and has to be prevented where the CSV is built. The pipeline already treats the column as load-bearing in the other direction: `vendor_attributes` is a required export header (featherarms-pipeline #123), so a column lost upstream fails the pipeline instead of reaching the CSV as blanks that would wipe the catalogue. A product that drops out of the import altogether keeps its attributes, as it keeps every other field.

### Open set, with two suppressions

Everything unpacks unless one of these applies:

1. **Automatic `pa_*` shadow, per product.** Key `k` is skipped on a product that already carries a taxonomy attribute `pa_<k with _ as ->`. `bullet_type` yields to `pa_bullet-type` only where the term exists, so a product the curated vocabulary has not reached still shows the vendor value. No list to maintain.
2. **Denylist.** A class constant filtered by `fa_toolkit_attributes_denylist`. Seed: `rsr_subcategory`, `rsr_description`, `boxes_per_case`, `units_per_case`, `packs_per_case`, `cans_per_case`, plus `reticle_type` (covered by `pa_reticle` under a different name). It lives in code so it is reviewed and versioned, and it feeds `ruleset_hash`.

An allowlist was rejected as the default: 327 keys that shift with category would make every new vendor field invisible until someone edits a list, which is the opposite of the operator's open-set framing. If the long tail proves too noisy on the storefront, the same filter turns the denylist into an allowlist without a redesign.

The typed `_fa_*` meta the content import also writes (`_fa_material`, `_fa_magnification`, `_fa_battery_type`, ...) is deliberately **not** a suppression source. WooCommerce renders none of it, so suppressing `material` on that basis would remove it from the 14,314 products where it is the only visible source.

### Type handling

| JSON value | Attribute value |
|---|---|
| string | trimmed, verbatim. Empty after trim: key skipped. |
| string `Y`/`N`/`yes`/`no`/`true`/`false` (case-insensitive) | `Yes` / `No` |
| `true` / `false` | `Yes` / `No` |
| number | its JSON text, no reformatting (`25.0000` stays `25.0000`; upstream's to fix) |
| `null` | key skipped |
| array of scalars | elements converted as above, nulls and empties dropped, joined with ` \| ` (WooCommerce's multi-value delimiter, so it renders as a list) |
| object, or array containing one | key skipped, counted `unsupported`; the contract forbids it |

A literal `|` inside a string would be read by WooCommerce as a value separator. None exist today; the unpacker replaces it with `/` and the test suite pins that.

Labels: key with `_` to spaces, title-cased (`rate_of_twist` becomes "Rate Of Twist"), overridable per key through `fa_toolkit_attributes_labels` for the ones that read badly (`ar_15_accessory`, `nij_level`).

### What the storefront needs

Nothing new for display. Visible local attributes already render in the "Additional information" tab (classic template, `wc_display_product_attributes()`) and in the Product Details block, alongside the `pa_*` rows. They also appear in the admin product editor, the WooCommerce REST and Store API `attributes` arrays, and anything else that reads product attributes (feeds, fa-wpmcp).

What local attributes do **not** give: layered-nav filters, sorting, or attribute archive pages. That is the accepted boundary. A key that earns a facet is promoted deliberately into a curated `pa_*` taxonomy upstream (Akeneo select, content CSV column), at which point the automatic shadow rule retires its local twin with no plugin change.

### Cost

- Code: one pure unpacker (cell + rules + existing entries in, entries + sidecar out), one runner, one listener, one CLI command, tests. The listener and drain loop are the media pattern again, roughly 600-800 lines with tests. If the drain loop is extracted for reuse, that is its own refactor issue, not part of this one.
- Runtime, first pass: about 49k products at two meta reads and four meta writes each, no HTTP. Estimated 5-10 minutes; unmeasured. The implementation issue measures it on local staging from `elapsed_ms` before any default budget is chosen, as #103 did for media.
- Runtime, steady state: one selection query when nothing changed, **provided SSI's rewrite of `_product_attributes` is byte-stable** (see Idempotency). If it is not, the first-pass cost recurs on every content import. Unmeasured.
- Storage: one more entry set inside an existing serialized row per product, plus three small meta rows (two markers, sidecar). About 147k new postmeta rows, against 313k for per-key postmeta.
- Risk carried: `_product_attributes` is a row SSI also writes. The design depends on SSI continuing to carry non-taxonomy entries through. A test pins the behaviour against a fixture of SSI's output, the row marker re-selects any product whose row SSI changed, and the summary's `collision` and `invalid` counts make drift visible.

## Alternatives rejected

**Per-key postmeta (`_fa_attr_<key>`).** About 313k new postmeta rows that nothing in WooCommerce reads. The plugin would own the render path, the REST exposure and the clearing of vanished keys, all to restate a JSON cell that is already on the product. Only useful for `meta_query` filtering, which nobody has asked for and postmeta does badly at this size.

**Read at render from the JSON.** The strongest alternative: no writes, idempotent by construction, a vanished key disappears for free, and it is about a quarter of the code (filter `woocommerce_display_product_attributes`, decode, append rows). Rejected because the values would exist only inside one template filter. They would be missing from the admin editor, the REST and Store API, product feeds and structured data, and that contradicts the operator's framing of these as WooCommerce product attributes. It remains the fallback if the staging measurement of the first pass is unacceptable. The pure unpacker is the same function either way, so switching costs the listener, not the rules.

**`pa_*` taxonomies for every key.** Ruled out by the operator. Also unworkable on the numbers: 327 taxonomies, and `dimension`, `other_features`, `type_color` and `size_finish` each exceed 2,000 distinct values, which would mean tens of thousands of single-use terms.

**SSI's own custom-attributes feature** (`custom-attributes-pro.php`). It maps one CSV column per attribute, declared in the import config. That is 327 columns and a config edit every time a vendor adds a field. It also writes only non-empty values and carries existing entries through, so a vanished key never clears.

## Implementation issues to file

1. **`AttributeUnpacker` pure core.** Cell + rules + existing `_product_attributes` in; new entries, sidecar list and counts out. Type table, label rule, `pa_*` shadow, denylist, collision rule, `|` handling. TDD against synthetic cells, including arrays and booleans that real data does not yet contain.
2. **`AttributeUnpackRunner` + marker selection.** Both markers (cell + rules; attributes row), both selection arms (stale inputs; sidecar without cell), sidecar-scoped removal, direct meta write with cache invalidation.
3. **`AfterImportAttributes` listener.** Hook at priority 20, filters, drain and budgets, summary line and action. Wired in `fa-toolkit.php`; autoload test as for media.
4. **`wp fa:attributes unpack` command** over the same runner: `--dry-run`, `--product`, `--force`.
5. **Staging measurement.** First-pass `elapsed_ms` on local staging once a content CSV carrying `_fa_attributes` exists; whether a second, unchanged import re-selects anything (SSI row byte-stability); decide default budgets; confirm the Additional information tab and Product Details block output on real products.
6. **Upstream, not this repo (pointer only, ifm#221):** float-mangled numerics, column-collision keys and truncated `other_features` values reach the storefront verbatim under this design.
