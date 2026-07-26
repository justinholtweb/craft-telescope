# Telescope

Per-entry Google Analytics 4 reporting inside the Craft CMS control panel.

Telescope answers the question editors actually ask — *how is this page doing?* — without
making them leave Craft, learn the GA4 interface, or be given a Google account at all. Open an
entry, see its page views, visitors, sessions, engagement, traffic sources and referrers for
that entry's URL.

- **Free**, and free of PHP dependencies. It talks to the Google Analytics Data API directly —
  no Metrix, no third-party analytics plugin, no Google client library.
- **Craft 5**, PHP 8.2+.

## What you get

| Where | What |
| --- | --- |
| **Analytics field** | A full report — overview cards, a timeline chart, and breakdowns by location, traffic source, referring page and landing page — anywhere you add it to a field layout. |
| **Entry sidebar** | An optional compact summary on every entry, with no field layout changes at all. |
| **Telescope section** | The property's most-viewed pages, filterable by site and period. |
| **Dashboard widget** | "Top pages" on the Craft dashboard. |
| **Download PDF** | One click, built in the browser — chart included. |
| **Print view** | A clean, standalone report page, with no JavaScript involved. |
| **Console commands** | Connection diagnostics, one-off reports, and cache warming for cron. |
| **Twig API** | `craft.telescope.*` for surfacing the same numbers on the front end. |

## Installation

```bash
composer require justinholtweb/craft-telescope
php craft plugin/install telescope
```

## Connecting to Google Analytics

Telescope uses a **Google service account** — the credential type designed for server-to-server
access. No browser consent flow, no refresh token to expire.

1. In the [Google Cloud console](https://console.cloud.google.com/), create (or pick) a project
   and **enable the Google Analytics Data API**.
2. Create a **service account**, then create a **JSON key** for it and download the file.
3. In Google Analytics, go to **Admin → Property access management** and add the service
   account's email address (`something@your-project.iam.gserviceaccount.com`) as a **Viewer**.
4. In Craft, go to **Settings → Plugins → Telescope** and fill in:
   - **Service account credentials** — paste the JSON, or give the path to the key file.
   - **GA4 property ID** — the numeric ID from **Admin → Property details**. This is *not* the
     `G-XXXXXXX` measurement ID.
5. Hit **Test connection**.

### Keeping the key out of project config

Both fields accept environment variables, which is the right place for a private key:

```dotenv
# .env
GOOGLE_ANALYTICS_CREDENTIALS="/path/to/service-account.json"
GOOGLE_ANALYTICS_PROPERTY_ID="123456789"
```

Then enter `$GOOGLE_ANALYTICS_CREDENTIALS` and `$GOOGLE_ANALYTICS_PROPERTY_ID` in the settings.

### OAuth instead

If you already have an OAuth client and refresh token for the Analytics API, switch
**Authentication method** to *OAuth refresh token* and supply the client ID, client secret and
refresh token. A service account is the better default; this exists so an existing set of
credentials does not have to be thrown away.

## Showing reports to editors

**As a field.** Create a new field of type **Analytics** and add it to any entry type's field
layout. It stores nothing — adding or removing it never touches your content tables — and
renders the live report for whatever element it is attached to.

**Without touching field layouts.** Turn on **Show on all entries** in the settings for a
compact summary in every entry's sidebar, optionally limited to chosen sections.

Either way, an editor needs the **View analytics reports** permission.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Authentication method | Service account | Or OAuth refresh token. |
| Service account credentials | — | JSON, a file path, or an env var holding either. |
| GA4 property ID | — | Numeric. Per-site overrides available on multi-site installs. |
| Filter by hostname | Off | Turn on when several Craft sites report into one GA4 property. |
| Default period | Last 28 days | Also accepts a raw GA4 date such as `90daysAgo`. |
| Path match type | Exact | Or *begins with* / *contains*, for section-wide roll-ups. |
| Include query strings | Off | Off means `/blog?page=2` counts towards `/blog`. |
| Report sections | All six | Each section is one API call — switch off what you don't need. |
| Table rows | 10 | Rows in each breakdown table. |
| Cache duration | 600s | Reports are cached; the Data API is rate limited. |
| Widget rows | 10 | Pages listed in the dashboard widget. |

### Multi-site

Sites can each point at their own GA4 property (**Per-site property IDs**), or share one
property. If they share one, turn on **Filter by hostname** — otherwise `/about` on every site
is the same page path and their numbers will be added together.

## Console commands

```bash
# Verify credentials, property access and API connectivity
php craft telescope/analytics/check

# One entry's report, by ID or by URL/path
php craft telescope/analytics/show 1234
php craft telescope/analytics/show /about --period=last90days

# The property's most-viewed pages
php craft telescope/analytics/top-pages --limit=25

# Pre-fetch reports so editors never wait on a cold cache (good cron fodder)
php craft telescope/analytics/warm news --limit=100

# Drop every cached report
php craft telescope/analytics/clear-cache
```

All commands accept `--site=<handle>`; the reporting ones also accept `--period=`.

## Twig

```twig
{% set report = craft.telescope.report(entry) %}

{{ report.overview.views|number_format }} views
{{ craft.telescope.duration(report.overview.averageSessionDuration) }} average session
{{ craft.telescope.percent(report.overview.engagementRate) }} engaged

{{ craft.telescope.chart(report.timeline)|raw }}

{% for page in craft.telescope.topPages(null, 'last7days', 5) %}
    {{ page.title }} — {{ page.views }}
{% endfor %}
```

`craft.telescope.report(element, period)` and `craft.telescope.reportForUrl(url, siteId, period)`
both return a `PageReport` with `overview`, `timeline`, `geography`, `sources`, `referrers`,
`landingPages` and `errors`.

## How page matching works

Telescope reports on the **page path** of an element's URL — `https://example.com/about/`
becomes `/about`. Trailing slashes are trimmed and query strings dropped, because a GA4 exact
match on `/about/` will not find hits recorded against `/about`.

If reports come back empty for a page you know gets traffic, check in GA4 (**Reports →
Engagement → Pages and screens**) what path is actually recorded, then adjust **Path match
type** or **Include query strings** to suit.

## Front-end libraries

The report screens load five pinned libraries from jsDelivr:

| Library | Used for |
| --- | --- |
| Chart.js 4.4.0 | The timeline chart |
| Hammer.js 2.0.8 + chartjs-plugin-zoom 2.0.1 | Drag, scroll and pinch zooming on that chart |
| html2canvas 1.4.1 + jsPDF 2.5.1 | The **Download PDF** button |

They load only on screens that actually draw a full report, so an entry showing just the compact
sidebar panel does not pay for them. Nothing is loaded on the front end.

If your control panel runs under a content security policy that forbids third-party scripts, or
it has to work offline, use the **Print** view instead: it renders the same report — chart
included, as server-generated SVG — with no JavaScript at all.

## Caching and quota

Every report section is a separate Data API call, so a full report costs six. Reports are cached
for ten minutes by default and served from cache thereafter; failures are never cached, so
fixing a credential problem takes effect immediately. On a busy site, run
`telescope/analytics/warm` from cron and lower the number of enabled sections.

## Troubleshooting

**"That looks like a measurement ID"** — you have entered `G-XXXXXXX`. Telescope needs the
numeric property ID from **Admin → Property details**.

**"User does not have sufficient permissions for this property"** — the service account email
has not been added under **Admin → Property access management**.

**"invalid_grant … account not found"** — the key has been revoked or belongs to a deleted
service account. It can also mean the server's clock is out of sync.

**Everything reports zero** — the page path Telescope is matching does not match what GA4
recorded. Run `php craft telescope/analytics/show <id>` to see the path being queried.

## Development

```bash
composer install
composer test      # Pest
composer phpstan
composer ecs
```

The test suite runs without booting Craft: the plugin's logic lives in plain PHP classes behind
seams for HTTP, tokens and the clock, so the whole suite runs in under a second.

## License

[The Craft License](LICENSE.md). Telescope is free.
