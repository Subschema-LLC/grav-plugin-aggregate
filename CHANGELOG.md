# v1.1.0
## 10/11/2026

* New installation mode setting: Tracker only (unchanged v1.0 behavior) or Tag manager lite, which loads the hosted container (`/tms-lite/sites/<site-id>/lib.js`) instead of `aggregate.js`; the tracker then runs as a tag inside the container
* New consent banner setting (a single choice, at most one banner per page): None, Built-in consent manager lite (`/cmp-lite/sites/<site-id>/consent.js`), or Standalone banner (`/standalone-cmp/sites/<site-id>/consent.js`); the banner loads before the tracker or container in both modes, matching AGGREGATE's documented snippets
* The Admin form shows the derived tag manager site ID (first 24 characters of the website token's SHA-256) read-only; hosted script URLs for the banner and container derive it the same way
* Consent default and strict profile settings are documented as tracker-mode only; all v1.0 gates (no Grav Admin injection, Do Not Track, disabled or unconfigured silence, Grav Admin user exclusion) apply in both modes

# v1.0.0
## 10/11/2026

* Initial release
    * Tracker injection on public HTML pages using the documented URL-parameter configuration form, with `defer` and `referrerpolicy="no-referrer"`, and no inline JavaScript
    * Grav Admin settings for the AGGREGATE instance URL, website token, consent default, strict collection profile opt-in, Do Not Track respect, Grav Admin user exclusion, and the minified tracker build
    * No injection into Grav Admin, into non-HTML output, when unconfigured, or when the plugin is disabled
