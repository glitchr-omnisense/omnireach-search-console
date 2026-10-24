<?php

namespace Omnireach\SearchConsole;

use Omnireach\Config;
use Omnireach\Exception\InvalidConfigException;
use Omnireach\Http\JsonApi;
use Omnireach\ProviderFactory;
use Omnireach\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Search Console, from its options:
 *
 *   site          the property: "sc-domain:example.org" (a domain property) or "https://example.org/"
 *   credentials   a service account's JSON key, or the path of its file - the account added as a user
 *                 of the property (Search Console, Settings, Users and permissions); or:
 *   access_token  a token with the webmasters.readonly scope, obtained elsewhere
 *   data_state    "final" (the default: complete days only) or "all" (the last days, provisional)
 *   url           https://searchconsole.googleapis.com/webmasters/v3
 */
final class SearchConsoleFactory extends ProviderFactory
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireach.factory_name' => 'search-console',
            'omnireach.factory_title' => 'Google Search Console',
            'omnireach.required_options' => ['site'],
            'credentials' => '',
            'access_token' => '',
            'data_state' => 'final',
            'url' => 'https://searchconsole.googleapis.com/webmasters/v3',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $http = $this->http ?? throw new InvalidConfigException('The "search-console" provider needs an HTTP client: give one to SearchConsoleFactory.');
        $credentials = $c->string('credentials');

        return new SearchConsoleProvider(
            new JsonApi($http, 'Search Console'),
            new GoogleToken(new JsonApi($http, 'Google OAuth'), self::SCOPE, null !== $credentials ? GoogleToken::credentials($credentials) : null, $c->string('access_token')),
            (string) $c->string('site'),
            $c->string('data_state') ?? 'final',
            rtrim($c->string('url') ?? 'https://searchconsole.googleapis.com/webmasters/v3', '/'),
        );
    }
}
