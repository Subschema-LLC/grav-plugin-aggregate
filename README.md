# AGGREGATE plugin for Grav

Privacy-first, self-hosted analytics for [Grav](https://getgrav.org). This
plugin adds the [AGGREGATE](https://github.com/Subschema-LLC/aggregate)
tracker to your Grav site. AGGREGATE stores metrics as documented SQL views
in a database on your own server, ready for BI tools and AI assistants. No
analytics data goes to a third party, and anonymous mode collects no visitor
ID, no IP address, no user agent and no exact timestamp.

The plugin itself stores nothing and sets no cookies. It emits one script
tag that loads the tracker from your own AGGREGATE instance, configured
entirely through the script URL, so there is no inline JavaScript and the
tag is friendly to strict Content Security Policies.

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
     installation, e.g. `https://analytics.example.com`. The tracker script
     and its `/api/receive` endpoint are derived from this URL.
   - **Website token** — the token from step 1.

   The tracker stays off until both values are set.
3. Adjust the remaining settings to taste:
   - **Consent default** — the consent state the tracker assumes before
     the visitor chooses: *denied until the visitor chooses* (default),
     *granted*, or *no default sent*.
   - **Strict collection profile** — opt this site into strict collection:
     the tracker sends only the page path, event name and goal; it never
     reads cookies, storage, the referrer, screen size or the query string.
   - **Respect Do Not Track** — when a browser sends the `DNT` header, the
     tracker is not added to the page at all (default on).
   - **Exclude Grav Admin users** — visitors logged in with an account
     that has Grav Admin access are not tracked (default on).
   - **Minified tracker** — load the minified build (default on).

The tracker is never added to Grav Admin pages or to non-HTML output such
as feeds.

## Wiring a consent manager

The tracker loads with the consent default you configured. When your
consent manager (cookie banner, CMP) records the visitor's choice, pass it
to the tracker:

```javascript
// Visitor accepted enhanced analytics:
window.Aggregate.setConsent(true);

// Visitor declined, or withdrew consent:
window.Aggregate.setConsent(false);
```

Call these after the tracker has loaded. The tracker announces itself by
dispatching an `aggregate:tracker-ready` event on `document`, so consent
code can wait for it:

```javascript
document.addEventListener('aggregate:tracker-ready', function () {
  if (visitorAlreadyConsented()) {
    window.Aggregate.setConsent(true);
  }
});
```

The plugin intentionally ships no banner of its own. In anonymous mode,
page views are recorded in coarse, hour-bucketed form without identifiers;
`setConsent(true)` enables the enhanced mode described in the
[AGGREGATE tracking guide](https://github.com/Subschema-LLC/aggregate/blob/master/docs/TRACKING.md).

## Custom events and goals

No plugin support is needed beyond the tracker itself.

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
affirmative choice.

## License

MIT — see [LICENSE](LICENSE). The plugin is a separate work from the
AGGREGATE server, which is licensed under AGPL-3.0.
