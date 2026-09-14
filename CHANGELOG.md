# Release Notes for Telescope

## 5.3.0 - 2026-09-14

### Added

- **A graphical dashboard.** The Telescope control panel screen was a top-pages table; it is now a
  site-wide dashboard — headline totals, a zoomable traffic-over-time chart, and breakdown panels
  for traffic sources, devices, top countries and browsers, with the top-pages table below.
- **Dashboard panels** setting. Each panel is one API call, so the optional ones can be switched
  off the same way report sections already could.
- `Analytics::getSiteReport()`, `ReportBuilder::buildSiteReport()` and the `SiteReport` model:
  the property-wide counterpart to the existing per-page report, cached the same way and with the
  same per-panel failure handling — a quota error on one panel no longer blanks the screen.

### Fixed

- **The site and period dropdowns did nothing.** Craft's `forms.select` macro applies a passed
  `class` to the wrapping `<div class="select">` rather than to the `<select>`, so the JS hooks
  the change listener looks for never reached the element it sees. Both switchers, on the
  dashboard and on an entry's report, were inert. The hooks now go on the `<select>` via
  `inputAttributes`, and the listener matches either element.

## 5.2.0 - 2026-09-01

### Added

- **Per-site credentials.** Sites could already point at their own GA4 property, but they all
  shared one Google identity — which does not hold when a multi-site install's properties live
  in different Google accounts. **Per-site service accounts** and **Per-site refresh tokens**
  sit beside the existing per-site property IDs and fall back to the default when left blank,
  so single-identity installs are unchanged. The OAuth client ID and secret stay shared: one
  application, one consent screen; what differs per site is who authorised it.
- `Settings::getCredentialsForSite()` and `getRefreshTokenForSite()`; `isConfigured()` and
  `Analytics::createTokenProvider()` are now site-aware. Providers are still memoised by
  credential rather than by site, so sites sharing one also share its access token and refresh.
- A bad per-site service account key now names the site it belongs to instead of failing as
  "the credentials".

### Changed

- `telescope/analytics/check` checks **every** site by default, and accepts `--site=<handle>`.
  A single verdict for the primary site would hide a second site whose access had lapsed.
- **Test connection** on the settings screen picks a site on multi-site installs.

## 5.1.0 - 2026-09-01

### Added

- `Analytics::getReport()` and `getReportForElement()` take an optional `$sections` argument, so
  a caller that only needs the headline numbers pays for one API call instead of six. Handy for
  listing screens that show a view count per row. It can only narrow what the settings already
  enable, never widen it, and section-limited reports are cached separately from full ones.
- `Analytics::createBuilder()` accepts a prepared `ReportOptions`, and `createOptions()` takes
  the same optional `$sections`.

## 5.0.0 - 2026-07-26

Initial release.

### Added

- Per-entry Google Analytics 4 reporting in the Craft control panel: overview totals, a timeline
  chart, and breakdowns by location, traffic source, referring page and landing page.
- **Analytics** field type — a read-only report for any element with a public URL. Stores
  nothing, so adding or removing it never touches content tables.
- Optional compact analytics panel in every entry's sidebar, with no field layout changes
  required, limitable to chosen sections.
- **Telescope** control panel section listing the property's most-viewed pages, filterable by
  site and period.
- **Top pages** dashboard widget.
- Interactive timeline chart (Chart.js) with drag, scroll and pinch zoom, pan, and a reset control.
- **Download PDF** — a one-click client-side export (html2canvas + jsPDF) that keeps cards,
  sections and tables from being sliced across page breaks. On paper the breakdown tables drop
  from four columns to two, and truncated values wrap in full rather than being cut short.
- Print view — a standalone, print-optimised page with the CP's chrome stripped out, rendering
  the chart as server-generated SVG so it needs no JavaScript at all.
- Direct Google Analytics Data API integration via a service account (recommended) or an OAuth
  refresh token. No third-party analytics plugin is required.
- Per-site GA4 property IDs, plus an optional hostname filter for installs where several sites
  report into one property.
- Configurable reporting period, path match type, query-string handling, enabled report sections,
  table row counts and cache duration.
- Console commands: `telescope/analytics/check`, `show`, `top-pages`, `warm` and `clear-cache`.
- `craft.telescope.*` Twig API for using the same reports on the front end.
- `telescope:viewReports` user permission.

### Notes

- Chart.js, Hammer.js, chartjs-plugin-zoom, html2canvas and jsPDF are loaded from jsDelivr at
  pinned versions, and only on screens that draw a full report. There are no PHP dependencies
  beyond Craft itself.
- Credentials and property IDs can be supplied as environment variables, keeping private keys
  out of project config.
