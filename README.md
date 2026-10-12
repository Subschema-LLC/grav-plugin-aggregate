# AGGREGATE plugin for Grav

Privacy-first, self-hosted analytics for [Grav](https://getgrav.org). This
plugin adds [AGGREGATE](https://github.com/Subschema-LLC/aggregate) to your
Grav site, in either of AGGREGATE's installation modes: the tracker loaded
directly, or the tag manager lite container. AGGREGATE stores metrics as
documented SQL views in a database on your own server, ready for BI tools
and AI assistants. No analytics data goes to a third party, and anonymous
mode collects no visitor ID, no IP address, no user agent and no exact
timestamp.

The plugin itself stores nothing and sets no cookies. It emits script tags
that load the tracker, the tag container and optionally one consent banner
from your own AGGREGATE instance. There is no inline JavaScript, so the
tags are friendly to strict Content Security Policies.

## Requirements

- Grav 1.7 or Grav 2.0
- A running AGGREGATE instance (your own server) with a website registered
  in it

## Installation

### Via GPM (recommended)

Once the plugin is listed in the Grav repository:

```bash
bin/gpm install aggregate
```

or install it from the Plugins page of Grav Admin.

### Manual installation

Download the latest release archive from
[GitHub](https://github.com/Subschema-LLC/grav-plugin-aggregate/releases),
extract it into `user/plugins/`, and rename the extracted folder to
`aggregate`, so the plugin lives at `user/plugins/aggregate`.

## Configuration

1. In AGGREGATE, register this website (Websites page in the AGGREGATE
   dashboard, or `php bin/console app:create-website` on the AGGREGATE
   server) and copy its public website token.
2. In Grav Admin, open Plugins → AGGREGATE (or edit
   `user/config/plugins/aggregate.yaml`) and set:
   - **AGGREGATE instance URL** — the base URL of your AGGREGATE
     installation, e.g. `https://analytics.example.com`. Every script URL
     is derived from this URL.
   - **Website token** — the token from step 1.

   All scripts stay off until both values are set.
3. Choose the **installation mode** and **consent banner** (below), then
   adjust the remaining settings to taste:
   - **Consent default** — the consent state the tracker assumes before
     the visitor chooses: *denied until the visitor chooses* (default),
     *granted*, or *no default sent*. Tracker mode only.
   - **Strict collection profile** — opt this site into strict collection:
     the tracker sends only the page path, event name and goal; it never
     reads cookies, storage, the referrer, screen size or the query string.
     Tracker mode only.
   - **Respect Do Not Track** — when a browser sends the `DNT` header,
     none of the AGGREGATE scripts are added to the page at all (default
     on).
   - **Exclude Grav Admin users** — visitors logged in with an account
     that has Grav Admin access are not tracked (default on).
   - **Minified scripts** — load the minified builds (default on).

The scripts are never added to Grav Admin pages or to non-HTML output such
as feeds, in either mode.

## Installation modes

### Tracker only (default)

The plugin loads the tracker itself:

```html
<script src="https://analytics.example.com/aggregate.js?min=1&endpoint=...&token=...&consent=0" defer referrerpolicy="no-referrer"></script>
```

Configuration travels in the script URL (the documented URL-parameter
form). A page view is recorded automatically on load.

### Tag manager lite

The plugin loads the hosted tag container for this website and does **not**
load `aggregate.js`:

```html
<script src="https://analytics.example.com/tms-lite/sites/SITE_ID/lib.js?min=1" defer referrerpolicy="no-referrer"></script>
```

The tracker runs as a tag inside the container instead. Three steps inside
AGGREGATE are required, or nothing is collected:

1. **Enable the container**: in AGGREGATE, open
   **Collection → Tag manager lite**, choose this website, and enable the
   tag manager (equivalently, set `tag_manager.enabled: true` in the
   site's YAML file).
2. **Add the tracker as a tag**: on the same page, use **Add as a new tag**
   next to the tracker URL (or copy the tracker URL into a script tag
   yourself). Choose the tag's consent category: `none` lets anonymous
   tracking start before an analytics choice; `analytics` waits for
   consent before any tracker code loads. Save.
3. **Remove separate tracker snippets** if you ever installed the tracker
   directly (in your theme, or through another tag manager), so the
   automatic page view is not sent twice.

Tags, triggers, variables and the data layer are then managed entirely in
AGGREGATE; see its
[tag manager guide](https://github.com/Subschema-LLC/aggregate/blob/master/docs/TAG-MANAGER.md).

### The site ID

The container and banner URLs contain a site ID. You never type it: the
plugin derives it from the website token, as the first 24 characters of
the token's SHA-256 — the same derivation AGGREGATE uses. The Admin form
shows it read-only next to the banner setting. Compare it in AGGREGATE
under **Collection → Tag manager lite**, or on the server with:

```bash
php bin/console app:tag-manager:sites
```

## Consent banners

At most one banner per page. The **Consent banner** setting is a single
choice, never a combination:

- **None / my own consent manager** — the plugin loads no banner. Connect
  your own CMP as described below.
- **Built-in consent manager lite** — loads
  `/cmp-lite/sites/SITE_ID/consent.js` from your AGGREGATE instance,
  before the tracker or the container. It works with **both** installation
  modes: AGGREGATE's Setup offers the consent choice separately from the
  installation method, and its direct-tracker snippet loads this banner
  before `aggregate.js`, so a stored choice takes precedence over the
  tracker's consent default. Configure wording, colors and buttons in
  AGGREGATE under **Collection → Consent manager lite**.
- **Standalone banner** — loads
  `/standalone-cmp/sites/SITE_ID/consent.js`, the larger independent
  MicroConsent drop-in (regional examples, Global Privacy Control
  handling, optional privacy-request form), in either mode. Configure it
  in AGGREGATE under **Setup → Install scripts** (standalone option).

## Wiring your own consent manager

With **Consent banner** set to *None*, connect your existing consent
manager (cookie banner, CMP) yourself. For the tracker, pass the visitor's
choice after the tracker has loaded:

```javascript
// Visitor accepted enhanced analytics:
window.Aggregate.setConsent(true);

// Visitor declined, or withdrew consent:
window.Aggregate.setConsent(false);
```

The tracker announces itself by dispatching an `aggregate:tracker-ready`
event on `document`, so consent code can wait for it:

```javascript
document.addEventListener('aggregate:tracker-ready', function () {
  if (visitorAlreadyConsented()) {
    window.Aggregate.setConsent(true);
  }
});
```

In tag manager mode, hand the full category state to the container
instead, after it loads and whenever it changes:

```javascript
window.AggregateTags.setConsent({
  analytics: true,
  functional: false,
  marketing: false
});
```

In anonymous mode, page views are recorded in coarse, hour-bucketed form
without identifiers; `setConsent(true)` enables the enhanced mode
described in the
[AGGREGATE tracking guide](https://github.com/Subschema-LLC/aggregate/blob/master/docs/TRACKING.md).

## Custom events and goals

In tracker mode, no plugin support is needed beyond the tracker itself.

From JavaScript, after the tracker has loaded:

```javascript
window.Aggregate.emit('signup_click', { plan_type: 'pro' }, 'signup');
```

Or declaratively, with data attributes in your Twig templates or Markdown:

```html
<button data-aggregate-event="plan_click"
        data-aggregate-prop-plan="pro"
        data-aggregate-goal="signup">Choose Pro</button>
```

- `data-aggregate-event` — the event name. On a form it fires on submit;
  on any other element it fires on click.
- `data-aggregate-goal` — a goal defined in AGGREGATE's `config/goals.yaml`.
- `data-aggregate-prop-<key>` — a custom property, e.g.
  `data-aggregate-prop-plan` above; it may sit on the marked element or any
  element around it.

Custom properties must exist in the AGGREGATE data model; the server drops
anything else, and properties that require consent are only sent after an
affirmative choice. In tag manager mode, send events through the
container's data layer and tags instead.

## License

MIT — see [LICENSE](LICENSE). The plugin is a separate work from the
AGGREGATE server, which is licensed under AGPL-3.0.
