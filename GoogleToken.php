<?php

namespace Omnireach\SearchConsole;

use Omnireach\Exception\InvalidConfigException;
use Omnireach\Exception\ProviderException;
use Omnireach\Http\JsonApi;

/**
 * A bearer token for a Google API: the one given, or one obtained for a
 * service account - a JWT signed with its private key (RS256) exchanged at
 * its token_uri (the OAuth 2.0 JWT bearer grant, RFC 7523) - and kept until
 * a minute before it expires.
 *
 * The same class lives in omnireach/search-console and omnireach/ga4: two
 * providers, one grant, no shared package for forty lines.
 */
final class GoogleToken
{
    private int $expires = 0;

    /**
     * @param array<string, mixed>|null $serviceAccount the service account's JSON key, decoded (client_email, private_key, token_uri)
     * @param ?\Closure(): int          $clock          the present as a UNIX time (a test's); time() when null
     */
    public function __construct(
        private readonly JsonApi $api,
        private readonly string $scope,
        #[\SensitiveParameter] private readonly ?array $serviceAccount = null,
        #[\SensitiveParameter] private ?string $token = null,
        private readonly ?\Closure $clock = null,
    ) {
        if (null === $serviceAccount && null === $token) {
            throw new InvalidConfigException('A Google API needs a service account\'s key or an access token.');
        }
        if (null !== $token) {
            $this->expires = \PHP_INT_MAX;
        }
    }

    /**
     * A service account's key: its JSON, or the path of its file.
     *
     * @return array<string, mixed>
     */
    public static function credentials(#[\SensitiveParameter] string $jsonOrPath): array
    {
        $json = str_starts_with(ltrim($jsonOrPath), '{') ? $jsonOrPath : (is_file($jsonOrPath) ? (string) file_get_contents($jsonOrPath) : '');
        $key = json_decode($json, true);
        if (!\is_array($key) || !\is_string($key['client_email'] ?? null) || !\is_string($key['private_key'] ?? null)) {
            throw new InvalidConfigException('The credentials are not a service account\'s JSON key (client_email, private_key), nor the path of one.');
        }

        /** @var array<string, mixed> $key */
        return $key;
    }

    public function get(): string
    {
        $now = $this->now();
        if (null === $this->token || $now >= $this->expires) {
            $answer = $this->api->request('POST', $this->tokenUri(), ['body' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion($now)]], 'an access token');
            $this->token = $answer->string('access_token') ?? throw new ProviderException('Google gave no access token.');
            $this->expires = $now + max(60, ($answer->int('expires_in') ?? 3600) - 60);
        }

        return $this->token;
    }

    /** The signed JWT the token is asked with. */
    public function assertion(int $now): string
    {
        $account = $this->serviceAccount ?? throw new InvalidConfigException('No service account to sign with.');
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $input = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode([
            'iss' => $account['client_email'] ?? null,
            'scope' => $this->scope,
            'aud' => $this->tokenUri(),
            'iat' => $now,
            'exp' => $now + 3600,
        ], \JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private(\is_string($account['private_key'] ?? null) ? $account['private_key'] : '');
        if (false === $key || !openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256)) {
            throw new InvalidConfigException('The service account\'s private key cannot sign.');
        }

        return $input.'.'.$encode(\is_string($signature) ? $signature : '');
    }

    private function tokenUri(): string
    {
        $uri = $this->serviceAccount['token_uri'] ?? null;

        return \is_string($uri) && '' !== $uri ? $uri : 'https://oauth2.googleapis.com/token';
    }

    private function now(): int
    {
        return null !== $this->clock ? ($this->clock)() : time();
    }
}
