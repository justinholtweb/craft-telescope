# Release Notes for Telescope

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
