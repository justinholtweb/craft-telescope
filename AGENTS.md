# Agents Guide — Telescope (Craft CMS Plugin)

Context for AI agents working on the Telescope codebase.

## Project Overview

Telescope brings per-entry Google Analytics 4 reporting into the Craft control panel. It is a
**free** plugin with **no PHP dependencies** beyond Craft itself — no Metrix, no Google client
library. Not depending on Metrix is the point of the project; the Data API integration, the
service-account JWT and the SVG chart are written here.

The control panel report loads five pinned front-end libraries from jsDelivr (Chart.js, Hammer,
chartjs-plugin-zoom, html2canvas, jsPDF) through `TelescopeReportAsset`, and only on screens that
draw a full report. The print view uses none of them.

**Tech stack**: PHP 8.2+, Craft CMS 5, Yii2, Twig, vanilla JS
**Namespace**: `justinholtweb\telescope`
**Package**: `justinholtweb/craft-telescope`
**Handle**: `telescope`
**License**: Proprietary (The Craft License), distributed free

## Architecture

The organising principle is that **almost nothing needs Craft**. Craft-specific work is confined
to `services/Analytics`, the field, the widget, the controllers and the console command;
everything underneath is plain PHP behind three seams — HTTP transport, token provider, and an
injectable clock. That is what lets the whole test suite run in well under a second with no
database and no application boot.

```
src/
├── Plugin.php                       # Registers field, widget, routes, permission, sidebar hook
├── auth/
│   ├── TokenProviderInterface.php   # getToken(): AccessToken
│   ├── ServiceAccountTokenProvider  # JWT-bearer flow (the recommended path)
│   ├── RefreshTokenProvider         # OAuth refresh_token flow
│   ├── StaticTokenProvider          # Tests, and externally-minted tokens
│   ├── ServiceAccountCredentials    # Parses/validates Google's JSON key
│   ├── AccessToken                  # Token + expiry, with refresh leeway
│   └── Jwt.php                      # base64url, claim set, RS256 signing
├── ga4/
│   ├── Client.php                   # runReport, bearer auth, retry/backoff
│   ├── ReportRequest.php            # Fluent builder for runReport bodies
│   ├── ReportResponse.php           # Name-based access to rows
│   └── Period.php                   # Date ranges + presets
├── reports/
│   ├── ReportBuilder.php            # Six requests → a PageReport; seven → a SiteReport
│   ├── PageReport.php               # One page's report, array-serialisable
│   ├── SiteReport.php               # The property-wide dashboard, array-serialisable
│   ├── PageMetrics.php              # Overview totals + display cards
│   ├── ReportOptions.php            # Match type, hostname, limits, sections
│   ├── ReportSection.php            # Page-report section handles + normalisation
│   └── DashboardSection.php         # Dashboard panel handles + normalisation
├── helpers/
│   ├── PathHelper.php               # URL → GA4 pagePath; referrer classification
│   ├── Chart.php                    # Server-rendered SVG line chart
│   └── Format.php                   # Durations, percentages, compact numbers
├── http/                            # HttpClientInterface + Guzzle implementation
├── errors/                          # TelescopeException → AuthException / ApiException
├── models/Settings.php              # Plugin settings (env-parsed accessors)
├── services/Analytics.php           # Craft glue: sites, caching, credentials
├── fields/AnalyticsField.php        # Read-only field, dbType() === null
├── widgets/TopPagesWidget.php       # Dashboard widget
├── controllers/ReportsController    # CP screens, refresh, print, test-connection
├── console/controllers/             # check / show / top-pages / warm / clear-cache
├── twig/TelescopeVariable.php       # craft.telescope.*
├── templates/                       # CP templates
└── web/assets/cp/                   # CSS + a small delegated-events JS file
```

## Conventions and rules of thumb

- **No new PHP dependencies.** Not depending on other analytics plugins is the whole point. If
  something needs a library, write the small piece of it that is actually required (see
  `auth/Jwt.php`, `helpers/Chart.php`). Front-end libraries are fine, pinned, and confined to
  `TelescopeReportAsset`.
- **Keep logic out of Craft classes.** Anything worth testing belongs in `ga4/`, `reports/`,
  `auth/` or `helpers/`. Craft classes should read as glue.
- **Never let analytics break an edit screen.** Every failure path degrades to an empty report
  plus a message. `PageReport::withErrors()` and the `describeFailures()` collapsing in
  `ReportBuilder` exist for exactly this.
- **Auth failures short-circuit.** Bad credentials fail every section identically, so
  `ReportBuilder::build()` and `buildSiteReport()` stop at the first `AuthException` rather than
  making the remaining doomed round trips.
- **Page-level and site-level are separate.** `ReportSection` scopes one page's report,
  `DashboardSection` the property-wide dashboard. They overlap in name only — the dashboard's
  "sources" panel is unfiltered, the page report's is filtered to one path — and they are toggled
  independently because they are paid for on different screens.
- **Only clean reports are cached.** Caching a failure would keep an error on screen for the
  whole cache window after it was fixed.
- **Settings are never *required*.** A fresh install must be able to save its other settings
  before anyone has been to Google Cloud. Credentials are validated for correctness when
  present, not for existence.
- **Secrets stay out of logs and cache keys.** Token providers expose a `fingerprint()`; use it.

## Testing

Pest, split into two suites:

- `tests/Unit` — plain PHP, no Craft, no application.
- `tests/Feature` — exercises Craft base classes (the settings model) that work without a booted
  app, plus the shipped templates and asset bundles. `tests/bootstrap.php` requires Yii's
  `Yii.php` by hand so validators can be instantiated. `TemplateSyntaxTest` parses every `.twig`
  file with Craft's filters stubbed — syntax only, since no test here renders a screen.

Helpers live in `tests/Support`: `FakeHttpClient` scripts responses and records requests;
`Ga4` builds realistic Data API payloads. `tests/Pest.php` provides `fakeClient()`,
`serviceAccountArray()` and `testPrivateKey()` (a real throwaway RSA key, so the signing path is
genuinely exercised).

```bash
composer test      # pest
composer phpstan   # level 5
composer ecs       # add --fix to apply
```

## Gotchas worth remembering

- **`forms.select` puts your `class` on the wrapper, not the `<select>`.** Craft's macro merges a
  passed `class` into the containing `<div class="select">`. A JS hook given that way never
  reaches the element a delegated `change` listener sees, and the control silently does nothing —
  this is exactly how the site and period switchers shipped inert. Pass hooks via
  `inputAttributes: { class: [...] }`, and have listeners `closest()` up to the hook so either
  placement works.
- **A Twig comment inside an expression is a parse error.** `{# … #}` between the braces of a hash
  literal — say, inside a `forms.select({ … })` call — fails with `Unclosed "{"` and takes the
  whole screen down. Put the comment above the statement. `tests/Feature/TemplateSyntaxTest.php`
  parses every template so this cannot ship again.
- **Trailing slashes kill exact matches.** GA4 records `/about`; a Craft URL may end in `/`.
  `PathHelper::normalize()` is the single place this is handled.
- **`preserveAspectRatio="none"` stretches SVG text** along with the plot. The chart must scale
  uniformly.
- **html2canvas 1.4.1 cannot parse `color()` / `oklch()`**, and Craft 5's theme is full of
  `color(srgb …)`. `flattenColors()` converts every colour in the clone by painting it onto a
  1×1 canvas and reading the pixel back — general enough to survive future theme changes.
- **html2canvas clones the whole document per call.** Capture the panel once and crop the blocks
  out of that bitmap; capturing six blocks separately took 18 seconds and produced 11 MB.
- **html2canvas does not rasterise SVG, and a Chart.js canvas often reads back blank** (the
  browser keeps it on the GPU). The PDF therefore draws the server-rendered SVG into a canvas of
  the plugin's own and hands html2canvas the resulting PNG. That SVG must carry its colours as
  presentation attributes, an `xmlns`, and an explicit width/height — as a standalone image it
  gets no stylesheet and no intrinsic size.
- **A `<script>` element holds raw text**, so HTML entities inside it are never decoded. Markup
  parked in one must be carried as JSON, not as `|e('html')` output.
- **`overflow: hidden` text is clipped to the pixel by html2canvas**, shearing the descenders off
  every truncated cell. The capture stylesheet lets those cells wrap instead.
- **Measure crop offsets on the clone, not the live DOM.** Hiding the screen-only controls
  changes the layout, so live offsets crop the tops off sections.
- **jsPDF's `save()` is an own property of each instance**, not on the prototype — relevant when
  stubbing it during manual testing.
- **Craft's `.btn` sets `display`, which beats the `[hidden]` attribute.** Use Craft's `.hidden`
  class to hide a button.
- **A nested `<form>` inside Craft's settings form does not work.** The cache button posts over
  Ajax instead.
- **Project config writes are buffered** until the request ends. A bare PHP script that boots
  Craft must call `saveModifiedConfigData()` itself.
- **`G-XXXXXXX` is a measurement ID**, not a property ID — a mistake common enough to warrant its
  own validation message.
- **The print view inlines its CSS** rather than registering an asset bundle: going through the
  CP's head/body hooks lets every other installed plugin inject chrome into the PDF.
