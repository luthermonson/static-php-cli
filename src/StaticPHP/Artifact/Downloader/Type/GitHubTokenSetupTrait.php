<?php

declare(strict_types=1);

namespace StaticPHP\Artifact\Downloader\Type;

trait GitHubTokenSetupTrait
{
    /** Origin every GitHub REST API URL in this codebase is written against. */
    public const string GITHUB_API_ORIGIN = 'https://api.github.com';

    public function getGitHubTokenHeaders(): array
    {
        return self::getGitHubTokenHeadersStatic();
    }

    /**
     * Origin to send GitHub REST API requests to.
     *
     * A CI runner that builds the same set of packages over and over asks
     * GitHub for the same handful of releases every time, and those requests
     * are both rate-limited and the slowest part of a warm build. A caching
     * proxy in front of the API fixes that, and the daemon running the build
     * advertises one by exporting GHREL_PROXY into the job environment -- the
     * same way it already exports GOPROXY for Go.
     *
     * Unset means upstream, which is the only correct default: a laptop or a
     * GitHub-hosted runner has no proxy and must behave exactly as before.
     */
    public static function getGitHubApiBase(): string
    {
        $proxy = getenv('GHREL_PROXY');
        if (!is_string($proxy) || trim($proxy) === '') {
            return self::GITHUB_API_ORIGIN;
        }
        // tolerate both "http://host:1" and "http://host:1/"
        return rtrim(trim($proxy), '/');
    }

    /**
     * Point a GitHub REST API URL at the configured API base.
     *
     * Deliberately a prefix swap on an already-built URL rather than a base
     * the URL templates are assembled from: with GHREL_PROXY unset this
     * returns its argument untouched, so the no-proxy path cannot drift away
     * from what it produces today no matter what the templates grow into.
     *
     * Only URLs spc itself wrote against the canonical origin are rewritten.
     * URLs read back out of an API response (browser_download_url,
     * tarball_url) are left exactly as served -- the proxy rewrites those on
     * the way out, and second-guessing it here would break that.
     */
    public static function getGitHubApiUrl(string $url): string
    {
        $base = self::getGitHubApiBase();
        if ($base === self::GITHUB_API_ORIGIN || !str_starts_with($url, self::GITHUB_API_ORIGIN)) {
            return $url;
        }
        return $base . substr($url, strlen(self::GITHUB_API_ORIGIN));
    }

    public static function getGitHubTokenHeadersStatic(): array
    {
        // GITHUB_TOKEN support
        if (($token = getenv('GITHUB_TOKEN')) !== false && ($user = getenv('GITHUB_USER')) !== false) {
            logger()->debug("Using 'GITHUB_TOKEN' with user {$user} for authentication");
            $encoded = base64_encode("{$user}:{$token}");
            spc_add_log_filter([$user, $token, $encoded]);
            return ["Authorization: Basic {$encoded}"];
        }
        if (($token = getenv('GITHUB_TOKEN')) !== false) {
            logger()->debug("Using 'GITHUB_TOKEN' for authentication");
            spc_add_log_filter($token);
            return ["Authorization: Bearer {$token}"];
        }
        return [];
    }
}
