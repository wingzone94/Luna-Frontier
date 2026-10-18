# PSI integration verification

This branch preserves adopted D and integrates the topic-nav icon registrations, independent regression tests, icon-subset validator corrections, and the Node 22 CI job. It does not establish a production performance improvement.

The Typekit async, Material Symbols trim, scrollbar-gutter, and diagnostic hero-delay experiments are not included. The twelve statically unreferenced icons remain registered. Existing analytics, authentication and consent behavior is retained; OAuth completion, GA4 reception and consent transitions still need verification in a connected staging environment.

The validator distinguishes confirmed literal references, heuristic references and filter additions needing runtime verification. Exit 2 means runtime registration verification is needed; it does not instruct adding an already filtered glyph. Static absence is not a deletion guarantee.

Local validation used the normal WordPress URL with matching assets and restored the original theme, cache and selected settings. A limited mobile browser trace did not reproduce the earlier hero-before-FCP timing. This does not invalidate earlier traces or constitute a Lighthouse/production speed comparison.

Validation for this integration is recorded in the integration commit and delivery report. Node tests cover the original cases and the independent audit counterexamples.

## WordPress 6.9 compatibility correction

The first push exposed a CI failure: WordPress 6.9 can insert `type="text/javascript"` before `wp_script_attributes` runs. The earlier guard treated that standard classic type as an opt-out and left the sign-in pair synchronous. The integration now permits this classic type without removing or changing the attribute. Async clients, module/data script types, admin and login exclusions remain protected. Four regression tests cover the typed pair and exclusions. This compatibility correction does not establish a performance or authentication outcome.
