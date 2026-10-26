# omnireach/search-console

Google Search Console for [omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach): a
property's search queries, pages, countries and devices with their clicks, impressions, CTR and
average position, day by day or over a period - for a site taken over, the history of its pages
before and after. The provider calls:

| Operation | API | Request |
|---|---|---|
| token (a service account) | Google OAuth 2.0, JWT bearer grant | `POST https://oauth2.googleapis.com/token` (or the key's `token_uri`) |
| search performance | Search Console API v3, Search Analytics | `POST {url}/sites/{site}/searchAnalytics/query` |

`{url}` is `https://searchconsole.googleapis.com/webmasters/v3` by default; Google has no sandbox.
Google's PHP libraries are not used (`google/apiclient` and its generated services,
`google/analytics-data` on gRPC, `google/auth` on Guzzle): one JSON call per report and one
signed JWT, through the application's HTTP client.

## Installation

```sh
composer require omnireach/search-console symfony/http-client
```

Requires PHP 8.2, `ext-openssl` (to sign the JWT), `omnisense/omnireach` (1.x) and
`symfony/http-client-contracts` (3); any implementation of Symfony's `HttpClientInterface` does.

## Credentials

Either of:

- **A service account's JSON key** (`credentials`): a service account of a Google Cloud project,
  its key downloaded as JSON, and the account's e-mail (`client_email`) added as a user of the
  property in Search Console (Settings, Users and permissions). The key needs `client_email` and
  `private_key`; `token_uri` is used when present.
- **An access token** (`access_token`) with the `https://www.googleapis.com/auth/webmasters.readonly`
  scope, obtained elsewhere (an OAuth consent of the site's owner, say). It is used as it is and
  never refreshed: when it expires, Google answers 401 (`InvalidKeyException`).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `site` | string | - | yes | the property: `sc-domain:example.org` (a domain property) or `https://example.org/` (a URL-prefix property), exactly as Search Console lists it |
| `credentials` | string | `''` | one of the two | the service account's JSON key, or the path of its file |
| `access_token` | string | `''` | one of the two | a bearer token with the scope above; when both are given, the token is used |
| `data_state` | string | `final` | no | `final`: complete days only; `all`: the last days too, provisional |
| `url` | string | `https://searchconsole.googleapis.com/webmasters/v3` | no | the API's base URL |

Per query: `ReachQuery::$site` replaces `site` for one report (another property the account can
read).

## Capabilities

| | |
|---|---|
| contract | `SearchPerformanceInterface` |
| dimensions | query, page, country, device, date - combined freely |
| metrics | clicks, impressions, ctr, position (all four by default) |
| filters | on any of its dimensions, exact |
| `AudienceInterface` | no |

## Behaviour

**Token.** With a service account, the token is asked on the first report, not when the provider
is built: a JWT (`RS256`, header `{"alg":"RS256","typ":"JWT"}`, claims `iss` the account's e-mail,
`scope`, `aud` the token URI, `iat` now, `exp` now + 3600) signed with the key's private key, sent
as `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`. The token is kept in the provider
until a minute before its `expires_in` (3600 seconds when Google does not say). An answer without
`access_token` is a `ProviderException` (`Google gave no access token.`). A key that cannot sign
is an `InvalidConfigException`, at the first report.

**The query.** One `POST` per page of rows, with a bearer token:

```
POST https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aatelier.example.org/searchAnalytics/query
  json: {"startDate":"2026-10-01","endDate":"2026-10-31","dimensions":["query","country"],"dataState":"final","dimensionFilterGroups":[{"groupType":"and","filters":[{"dimension":"device","operator":"equals","expression":"mobile"}]}],"rowLimit":3,"startRow":0}
```

(the body sent for `new ReachQuery(2026-10-01, 2026-10-31, [Query, Country], [Clicks, Position], ['device' => 'mobile'], limit: 3)`,
seen on a recorded client in the harness, 2026-10-26)

- the site is URL-encoded in the path; the dimensions keep their names and their order;
- every filter is an `equals` filter of one `and` group; a `country` filter is sent lower-cased
  (`fra`), the others as given;
- `rowLimit` is the rows still wanted, at most 25,000, `startRow` the rows already read: the
  provider asks page after page until `limit` rows are read or a page comes back short;
- the metrics are not sent: Search Console always returns all four.

**Rows.** Each row's `keys` are its dimensions, in the query's order: countries (ISO 3166-1
alpha-3) upper-cased (`FRA`), devices lower-cased (`desktop`, `mobile`, `tablet`), queries and
pages (full URLs) as given, dates `Y-m-d`. Clicks and impressions are integers, the CTR rounded to
4 decimals, the position to 1; a metric not asked is left out. Rows keep Search Console's order;
the query's filters and limit are then applied ([apply()](https://github.com/glitchr-omnisense/omnireach/blob/1.x/docs/concepts.md#filters-and-limit)):
filter `country` with the upper-case alpha-3 code, as the rows carry it.

**Note.** With `data_state: all`, `Report::$note` is `The last days are provisional (dataState
"all").`; with `final`, none.

**Limits** (`capabilities()->limits`): load quotas per site, per user and per project, a query
grouped by page or query costing more; 16 months of data; the rows are the top ones (anonymized
queries are left out of a query breakdown, so the queries' clicks add up to less than the site's).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Registry;
use Omnireach\SearchConsole\SearchConsoleFactory;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new SearchConsoleFactory(HttpClient::create())], [
    'google' => ['factory' => 'search-console', 'options' => [
        'site' => 'sc-domain:example.org',
        'credentials' => '/secrets/search-console.json',   // or the JSON itself
    ]],
]);
$google = $registry->search('google');

// The queries of the last 28 days, by country.
foreach ($google->searchPerformance(ReachQuery::lastDays(28, [Dimension::Query, Dimension::Country]))->rows as $row) {
    printf("%-40s %s %4d clicks  position %4.1f\n", $row->dimension(Dimension::Query), $row->dimension(Dimension::Country), $row->metric(Metric::Clicks), $row->metric(Metric::Position));
}

// One old page, day by day, on mobile.
$history = $google->searchPerformance(new ReachQuery(
    new DateTimeImmutable('2026-03-01'), new DateTimeImmutable('2026-09-30'),
    [Dimension::Date], [Metric::Clicks, Metric::Impressions],
    ['page' => 'https://example.org/old-page', 'device' => 'mobile'],
    limit: 500,
));
```

In Symfony (no bundle: [the core's Symfony page](https://github.com/glitchr-omnisense/omnireach/blob/1.x/docs/symfony.md)):

```yaml
services:
    Omnireach\SearchConsole\SearchConsoleFactory:
        arguments: ['@http_client']
    Omnireach\Registry:
        arguments:
            $factories: ['@Omnireach\SearchConsole\SearchConsoleFactory']
            $config:
                google:
                    factory: search-console
                    options: { site: 'sc-domain:example.org', credentials: '%env(GOOGLE_SERVICE_ACCOUNT)%' }
```

The example needs a property and credentials: it was not run live.

## Errors

The provider's HTTP calls are named `Search Console` (the reports) and `Google OAuth` (the token)
in the messages:

- 401, 403: `InvalidKeyException` - a token expired or revoked, the service account not a user of
  the property (`Search Console refused the credentials (403) for the search analytics of
  sc-domain:example.org: ...`, Google's message after the colon);
- 404: `NotFoundException`; another status of 400 or more (429 a quota), or a body that is not
  JSON: `ProviderException`, its code the status;
- a network error: `UnreachableException`;
- no `site`: `InvalidConfigException` (`The "search-console" provider needs: site.`); neither
  credentials nor token, credentials that are not a key: `InvalidConfigException` when the provider
  is built.

A dimension, filter or metric it does not report (`source`, `entry_page`, `visits`...) is a
`NotSupportedException` before any call.

## Verified, and not

Tested offline (`Tests/SearchConsoleProviderTest.php`, in the core's harness, PHP 8.4): a query by
query and country with two filters, the request's URL and body, the bearer token; a service
account's token asked once and kept, the JWT's header and claims, its signature checked with the
public half of a key made by the test; credentials from JSON and from a file; the refusals; the
factory through a `Registry`. The answers are composed in the shape the Search Console reference
documents, not recorded. `Tests/BareTest.php` loads every class with the package's requirements
alone.

Not verified: no Google account was used; no call was made to Search Console or to Google's token
endpoint. Whether Search Console matches a `device` filter written `mobile` (rather than `MOBILE`)
is unchecked.

## Responsibility

An independent, unofficial client, affiliated with Google in no way. Whoever runs it complies with
the Search Console API's terms (<https://developers.google.com/webmaster-tools/terms>) and limits
(<https://developers.google.com/webmaster-tools/limits>): see the README.
