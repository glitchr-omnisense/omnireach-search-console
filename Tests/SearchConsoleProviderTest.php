<?php

namespace Omnireach\SearchConsole\Tests;

use Omnireach\Exception\InvalidConfigException;
use Omnireach\Exception\InvalidKeyException;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Row;
use Omnireach\Registry;
use Omnireach\SearchConsole\GoogleToken;
use Omnireach\SearchConsole\SearchConsoleFactory;
use Omnireach\SearchConsole\SearchConsoleProvider;
use Omnireach\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Search Console's answers are composed for these tests in the shape its
 * searchAnalytics.query reference documents (rows of keys, clicks,
 * impressions, ctr, position); they were not recorded. The service
 * account's key is made by the test: no Google account was used.
 */
final class SearchConsoleProviderTest extends TestCase
{
    public function testQueriesByCountry(): void
    {
        $http = self::http();
        $report = self::provider($http)->searchPerformance(new ReachQuery(new \DateTimeImmutable('2026-09-24'), new \DateTimeImmutable('2026-10-21'), [Dimension::Query, Dimension::Country], filters: ['page' => 'https://atelier.example.org/ateliers', 'country' => 'FRA']));

        self::assertSame([
            ['query' => 'atelier poterie strasbourg', 'country' => 'FRA', 'clicks' => 42, 'impressions' => 980, 'ctr' => 0.0429, 'position' => 3.6],
            ['query' => 'cours de poterie', 'country' => 'FRA', 'clicks' => 7, 'impressions' => 1450, 'ctr' => 0.0048, 'position' => 14.1],
        ], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows), 'the German row filtered out again on our side');
        self::assertSame('https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aatelier.example.org/searchAnalytics/query', $http->requests[0]['url']);
        self::assertSame([
            'startDate' => '2026-09-24',
            'endDate' => '2026-10-21',
            'dimensions' => ['query', 'country'],
            'dataState' => 'final',
            'dimensionFilterGroups' => [['groupType' => 'and', 'filters' => [['dimension' => 'page', 'operator' => 'equals', 'expression' => 'https://atelier.example.org/ateliers'], ['dimension' => 'country', 'operator' => 'equals', 'expression' => 'fra']]]],
            'rowLimit' => 1000,
            'startRow' => 0,
        ], $http->requests[0]['options']['json']);
        self::assertSame('example-token-not-real', $http->requests[0]['options']['auth_bearer']);
    }

    public function testAServiceAccountsToken(): void
    {
        [$key, $public] = self::serviceAccount();
        $http = self::http();
        $now = 1792800000;
        $token = new GoogleToken(new JsonApi($http, 'Google OAuth'), SearchConsoleFactory::SCOPE, $key, null, static fn () => $now);

        self::assertSame('example-access-token-not-real', $token->get());
        self::assertSame('example-access-token-not-real', $token->get(), 'kept');
        self::assertCount(1, $http->requests);
        self::assertSame('https://oauth2.googleapis.com/token', $http->requests[0]['url']);
        $body = $http->requests[0]['options']['body'];
        self::assertIsArray($body);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $body['grant_type']);
        $assertion = \is_string($body['assertion']) ? $body['assertion'] : '';
        [$header, $claims, $signature] = explode('.', $assertion);
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        self::assertSame(['iss' => 'omnireach-test@example-project.iam.gserviceaccount.com', 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600], json_decode($decode($claims), true));
        self::assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), $public, \OPENSSL_ALGO_SHA256), 'signed with the account\'s key');
    }

    public function testCredentialsFromAFile(): void
    {
        [$key] = self::serviceAccount();
        $file = (string) tempnam(sys_get_temp_dir(), 'sa');
        file_put_contents($file, (string) json_encode($key));
        try {
            self::assertSame($key['client_email'], GoogleToken::credentials($file)['client_email']);
            self::assertSame($key['client_email'], GoogleToken::credentials((string) json_encode($key))['client_email']);
        } finally {
            unlink($file);
        }
        $this->expectException(InvalidConfigException::class);
        GoogleToken::credentials('{"type":"authorized_user"}');
    }

    public function testRefusals(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('Search Console refused the credentials (403) for the search analytics of sc-domain:atelier.example.org: User does not have sufficient permission for site \'sc-domain:atelier.example.org\'. See also: https://support.google.com/webmasters/answer/2451999.');
        self::provider(RecordedHttpClient::routes(['POST /searchAnalytics/query' => [403, '{"error": {"code": 403, "message": "User does not have sufficient permission for site \'sc-domain:atelier.example.org\'. See also: https://support.google.com/webmasters/answer/2451999.", "status": "PERMISSION_DENIED"}}']]))->searchPerformance(ReachQuery::lastDays(28, [Dimension::Query]));
    }

    public function testTheFactory(): void
    {
        [$key] = self::serviceAccount();
        $http = self::http();
        $registry = new Registry([new SearchConsoleFactory($http)], ['google' => ['factory' => 'search-console', 'options' => ['site' => 'sc-domain:atelier.example.org', 'credentials' => (string) json_encode($key)]]]);

        self::assertSame(['searchPerformance'], $registry->get('google')->capabilities()->contracts());
        self::assertCount(3, $registry->search('google')->searchPerformance(ReachQuery::lastDays(28, [Dimension::Query, Dimension::Country], [Metric::Clicks]))->rows);
        self::assertSame([true, true], [str_ends_with($http->requests[0]['url'], '/token'), str_ends_with($http->requests[1]['url'], '/searchAnalytics/query')], 'a token for the service account, then the query');
        $this->expectException(InvalidConfigException::class);
        (new Registry([new SearchConsoleFactory($http)], ['g' => ['factory' => 'search-console', 'options' => ['site' => 'x']]]))->get('g');
    }

    private static function provider(RecordedHttpClient $http): SearchConsoleProvider
    {
        return new SearchConsoleProvider(new JsonApi($http, 'Search Console'), new GoogleToken(new JsonApi($http, 'Google OAuth'), SearchConsoleFactory::SCOPE, null, 'example-token-not-real'), 'sc-domain:atelier.example.org');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'POST /token' => (string) file_get_contents(__DIR__.'/Fixtures/token.json'),
            'POST /searchAnalytics/query' => (string) file_get_contents(__DIR__.'/Fixtures/queries.json'),
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: \OpenSSLAsymmetricKey} a service account made here, and its public key */
    private static function serviceAccount(): array
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($pair);
        openssl_pkey_export($pair, $private);
        $details = openssl_pkey_get_details($pair);
        $public = openssl_pkey_get_public(\is_array($details) && \is_string($details['key'] ?? null) ? $details['key'] : '');
        self::assertNotFalse($public);

        return [['type' => 'service_account', 'client_email' => 'omnireach-test@example-project.iam.gserviceaccount.com', 'private_key' => \is_string($private) ? $private : '', 'token_uri' => 'https://oauth2.googleapis.com/token'], $public];
    }
}
