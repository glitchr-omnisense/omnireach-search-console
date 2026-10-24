<?php

namespace Omnireach\SearchConsole;

use Omnireach\Capabilities;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Report;
use Omnireach\Model\Row;
use Omnireach\SearchPerformanceInterface;

/**
 * The Search Console API (https://developers.google.com/webmaster-tools):
 * POST sites/{site}/searchAnalytics/query - dimensions, "equals" filters,
 * pages of rows (startRow, rowLimit up to 25,000) - with a bearer token.
 */
final class SearchConsoleProvider implements SearchPerformanceInterface
{
    private const DIMENSIONS = ['query' => 'query', 'page' => 'page', 'country' => 'country', 'device' => 'device', 'date' => 'date'];

    /** The most rows Search Console returns in one call. */
    private const PAGE = 25000;

    public function __construct(
        private readonly JsonApi $api,
        private readonly GoogleToken $token,
        private readonly string $site,
        private readonly string $dataState = 'final',
        private readonly string $url = 'https://searchconsole.googleapis.com/webmasters/v3',
    ) {
    }

    public function getName(): string
    {
        return 'search-console';
    }

    public function getTitle(): string
    {
        return 'Google Search Console';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            searchPerformance: true,
            dimensions: [Dimension::Query, Dimension::Page, Dimension::Country, Dimension::Device, Dimension::Date],
            metrics: [Metric::Clicks, Metric::Impressions, Metric::Ctr, Metric::Position],
            terms: ['https://developers.google.com/webmaster-tools/terms', 'https://developers.google.com/webmaster-tools/limits'],
            limits: ['Search Analytics load quotas per site, per user and per project (see the limits page); a query grouped by page or query costs more', '16 months of data; the last two or three days provisional (data_state "all")', 'the rows are the top ones: anonymized queries are left out of a query breakdown'],
        );
    }

    public function searchPerformance(ReachQuery $query): Report
    {
        $metrics = $query->metricsOr([Metric::Clicks, Metric::Impressions, Metric::Ctr, Metric::Position]);
        $this->capabilities()->check($query, $metrics, 'Search Console');
        $site = $query->site ?? $this->site;
        $body = [
            'startDate' => $query->start->format('Y-m-d'),
            'endDate' => $query->end->format('Y-m-d'),
            'dimensions' => array_map(static fn (Dimension $d) => self::DIMENSIONS[$d->value] ?? $d->value, $query->dimensions),
            'dataState' => $this->dataState,
        ];
        if ([] !== $query->filters) {
            $body['dimensionFilterGroups'] = [['groupType' => 'and', 'filters' => array_map(static fn (string $dimension, string $value) => [
                'dimension' => self::DIMENSIONS[$dimension] ?? $dimension,
                'operator' => 'equals',
                'expression' => 'country' === $dimension ? strtolower($value) : $value,
            ], array_keys($query->filters), $query->filters)]];
        }

        $rows = [];
        for ($start = 0; \count($rows) < $query->limit; $start += self::PAGE) {
            $size = min(self::PAGE, $query->limit - \count($rows));
            $lines = $this->api->request('POST', $this->url.'/sites/'.rawurlencode($site).'/searchAnalytics/query', ['json' => $body + ['rowLimit' => $size, 'startRow' => $start], 'auth_bearer' => $this->token->get()], 'the search analytics of '.$site)->list('rows');
            foreach ($lines as $line) {
                $dimensions = [];
                foreach ($query->dimensions as $i => $dimension) {
                    $value = (string) $line->string('keys.'.$i);
                    $dimensions[$dimension->value] = match ($dimension) {
                        Dimension::Country => strtoupper($value),
                        Dimension::Device => strtolower($value),
                        default => $value,
                    };
                }
                $values = [];
                foreach ($metrics as $metric) {
                    $value = match ($metric) {
                        Metric::Clicks, Metric::Impressions => $line->int($metric->value),
                        Metric::Ctr => null !== ($ctr = $line->float('ctr')) ? round($ctr, 4) : null,
                        Metric::Position => null !== ($position = $line->float('position')) ? round($position, 1) : null,
                        default => null,
                    };
                    if (null !== $value) {
                        $values[$metric->value] = $value;
                    }
                }
                $rows[] = new Row($dimensions, $values);
            }
            if (\count($lines) < $size) {
                break;
            }
        }

        return new Report('search-console', $site, $query, $query->apply($rows), 'all' === $this->dataState ? 'The last days are provisional (dataState "all").' : null);
    }
}
