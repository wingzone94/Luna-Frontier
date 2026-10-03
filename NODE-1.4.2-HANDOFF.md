# Node 1.4.2 image repair — original handoff

> Historical checkpoint. Current implementation and verification: [IMAGE-REPAIR-REPORT.md](IMAGE-REPAIR-REPORT.md).

2026-10-02. User requested a handoff before implementation/release completion.

## Base and scope

- Repository: wingzone94/Luna-Frontier; target is **Node**, not Luna Frontier 2.0.
- Base master: `81a89a8a4f1b0075680b27b84881f1354ecb0a67` (Node 1.4.1).
- Working branch: `codex/node-1.4.2-image-repair`.
- AGENTS.md read. Local fetched tags contained no `*1.4.2*`; GitHub release listing contained no Node 1.4.2 release. Recheck before release.
- No master update, tag, release, production login, production DB access or production change.
- style.css/build.json remain 1.4.1. Existing node.zip is still the old tracked ZIP. Do not distribute it as 1.4.2.

## Confirmed code findings

The embedded compressor deleted old intermediate files without updating stored article URLs. The featured WebP path rewrote content and then deleted original files, with a queued deletion retry. These are possible recurrence paths, not proof of execution against production attachment 1266. The threshold of 2000 is not proven to be the original cause. The PNG URL returning WebP is not diagnosed.

## Working changes

- `inc/image-repair.php`: uploads-only path resolution, real image validation, attachment lineage checks, HTML/srcset/lazy attributes/Gutenberg/featured/metadata references; additive repair at the exact missing URL; journals; conflict checks.
- `inc/image-repair-admin.php`: Node Settings submenu; administrator + nonce; persisted scan and repair cursors; three posts per scan request and one result per repair request; results in non-autoloaded options.
- `assets/js/image-repair.js`: explicit scan/review/repair/resume UI, paginated results, pause, textContent rendering.
- `functions.php`: load the new admin module.
- `inc/featured-webp.php`: keep originals, stop automatic stored-content rewriting, use unique WebP target names, retain original metadata, neutralize older queued delete retries.
- Embedded `class-webp-converter.php`: keep original and old derivatives during convert/restore; unique new target; metadata failure checks/rollback; uploads source containment.
- Theme `wp_delete_file` filter also retains files deleted from older standalone `Node_IC_Converter::convert/restore` call stacks. Review this compatibility safeguard carefully.
- Tests: `node-image-repair-test.php`, `node-image-repair-ajax-test.php`; updated expectations in `node-featured-webp-test.php` to require retention.

## Verified

PHP 8.3.19, WordPress 6.9, MariaDB 11.4.5 isolated test runtime (not cybernode.local).

Executed:
`php vendor/bin/phpunit --filter 'Node_Image_Repair_Test|Node_Featured_Webp_Test|Node_Theme_Update_Test'`

Result: **16 tests, 98 assertions, all passed**. Includes missing old unscaled derivative with scaled metadata, metadata-only missing derivative, scaled source fallback, missing all sources, Japanese WebP, saved srcset/Gutenberg gallery preservation, HTML masquerading as an image, repeated execution, edit conflicts, path traversal/symlink rejection, unknown crop refusal, healthy file unchanged, original-file retention and existing updater helper behavior.

Important timing: the later original-lineage metadata additions, standalone deletion compatibility filter and newly added AJAX tests have NOT had the complete regression run yet. The passing result is not a release gate for the entire current diff.

New repair PHP files were linted earlier; lint all final changed files again. `git diff --check` passed at handoff.

## Limitations and remaining work (do not hide these)

1. This is WIP, not a release candidate. Finish review before publishing any ZIP.
2. The repair strategy deliberately does not modify article content or attachment metadata. It recreates exact missing URLs only when lineage, source file, dimensions and aspect ratio are supported. Unknown crop, GIF, unsafe paths, unavailable originals and ambiguous attachment identity stay unresolved. Verify whether this conservative coverage meets the user's requested cases.
3. The “restore” action currently checks conflicts and records restoration, but KEEPS generated files to protect shared references. Content/metadata were never changed. It is not a full file rollback. Either implement a safe, explicit rollback with reference/conflict checks, or obtain acceptance of this limitation; do not describe it as full restoration.
4. A persistent worker lock has no automatic expiry. Abnormal termination can require manual removal of `node_image_repair_worker` after confirming the old worker is dead. Improve safe crash recovery/resume and test it.
5. Per-row failures are logged while the batch continues. Retrying failed rows currently requires a new scan. Test interruption before/after journal publication and atomic hard-link publication. Hard-link support and permissions need server compatibility verification.
6. Review scan checkpoint durability, duplicate result handling after interruption, source changes, huge posts, malformed block attributes, external/data URLs, metadata consistency and resource bounds.
7. External HTTP is intentionally not performed; outside-uploads references are unknown, not “missing”. CDN/HTTP response validation remains a separate delivery check. The local HTML-body test does not establish production HTTP behavior.
8. AJAX permission/nonce/status selection/batch/resume tests are written but not executed yet. Additional compressor regression tests are needed.
9. Full Node 1.4.1 Settings AJAX update using the actual new ZIP, preserving directory case/theme mods/options, remains untested. Only pre-existing updater helper tests passed. Updater code and URLs were not changed.
10. No frontend Bun build or browser verification yet. No cybernode.local verification, no production checks.
11. Version bump, changelog/build metadata, release ZIP, archive contents/hash validation, PR and final report remain undone.

## Next steps

1. Read AGENTS.md and this handoff, inspect current branch/diff and latest master without overwriting others.
2. Review and finish the above correctness/recovery/rollback gaps. Run focused PHP/AJAX tests, add failure and compressor coverage.
3. Build with Bun; keep changes scoped to image repair. Test local WordPress UI and actual image serving, including non-image responses.
4. Change style.css, package.json and build.json/version metadata consistently to 1.4.2 (NODE_THEME_VERSION derives from the theme header). Add changelog.
5. Generate node.zip only after tests; root **Node/**; include runtime PHP under src/; exclude Git, credentials, test dependencies, development files and ZIPs. Follow HOW_TO_RELEASE.md.
6. Execute actual legacy AJAX updater in an isolated fixture, mocking only GitHub delivery to serve the candidate archive. Check theme directory name and settings retained.
7. Review source + ZIP in one branch/PR. Ask the user before master merge, tag/Release or production update. master version/build/ZIP must publish together. After approval, verify the exact raw URLs before claiming availability from Node Settings.

## Existing distribution contract

- Version: https://raw.githubusercontent.com/wingzone94/Luna-Frontier/refs/heads/master/style.css
- Build: https://raw.githubusercontent.com/wingzone94/Luna-Frontier/refs/heads/master/build.json
- ZIP: https://github.com/wingzone94/Luna-Frontier/raw/refs/heads/master/node.zip
- `inc/ajax.php` uses manage_options + nonce and the existing staged directory swap helpers in `inc/theme-setup.php`.
- A branch or PR alone is not distribution. Master merge triggers the repository's automatic tag workflow according to HOW_TO_RELEASE.md.

## Local-only runtime breadcrumbs

Workspace: `/workspace/scratch/a5ed9526f427/Luna-Frontier`.
Runtime: `/workspace/scratch/a5ed9526f427/runtime/` contains standalone PHP, Composer, WordPress, MariaDB and a test runner/log; not committed or distributed.
`test-results.log` records the 16/98 passing run. `runtime-test.sh` has since been adjusted to run the new AJAX class but has not yet been executed in that form. It starts the local DB and test process in one invocation. Use your own normal PHP/WordPress test runtime if these temporary files are unavailable. No production credentials were used.
