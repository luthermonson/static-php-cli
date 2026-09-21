<?php

declare(strict_types=1);

namespace Tests\StaticPHP\Artifact\Downloader\Type;

use PHPUnit\Framework\TestCase;
use StaticPHP\Artifact\Downloader\Type\GitHubRelease;
use StaticPHP\Artifact\Downloader\Type\GitHubTarball;

/**
 * @internal
 */
class GitHubApiBaseTest extends TestCase
{
    public function testUnsetProxyLeavesUpstreamUrlsUntouched(): void
    {
        $this->withProxy(null, function (): void {
            $this->assertSame('https://api.github.com', GitHubRelease::getGitHubApiBase());

            foreach ([GitHubRelease::API_URL, GitHubRelease::ASSET_URL, GitHubRelease::RELEASE_BY_TAG_URL, GitHubTarball::API_URL] as $url) {
                $this->assertSame($url, GitHubRelease::getGitHubApiUrl($url));
            }
        });
    }

    public function testEmptyProxyIsTreatedAsUnset(): void
    {
        // an exporter that always sets the variable and sometimes leaves it
        // blank must not point spc at the empty origin
        foreach (['', '   '] as $value) {
            $this->withProxy($value, function (): void {
                $this->assertSame('https://api.github.com', GitHubRelease::getGitHubApiBase());
                $this->assertSame(GitHubRelease::API_URL, GitHubRelease::getGitHubApiUrl(GitHubRelease::API_URL));
            });
        }
    }

    public function testProxyReplacesOriginAndKeepsPath(): void
    {
        $this->withProxy('http://10.0.0.1:8080', function (): void {
            $this->assertSame('http://10.0.0.1:8080', GitHubRelease::getGitHubApiBase());
            $this->assertSame(
                'http://10.0.0.1:8080/repos/{repo}/releases',
                GitHubRelease::getGitHubApiUrl(GitHubRelease::API_URL)
            );
            $this->assertSame(
                'http://10.0.0.1:8080/repos/static-php/package-bin/releases/tags/v1.2.3',
                GitHubRelease::getGitHubApiUrl('https://api.github.com/repos/static-php/package-bin/releases/tags/v1.2.3')
            );
            $this->assertSame(
                'http://10.0.0.1:8080/repos/{repo}/{rel_type}',
                GitHubTarball::getGitHubApiUrl(GitHubTarball::API_URL)
            );
        });
    }

    public function testTrailingSlashIsTrimmed(): void
    {
        $this->withProxy('http://10.0.0.1:8080/', function (): void {
            $this->assertSame('http://10.0.0.1:8080', GitHubRelease::getGitHubApiBase());
            $this->assertSame(
                'http://10.0.0.1:8080/repos/{repo}/releases',
                GitHubRelease::getGitHubApiUrl(GitHubRelease::API_URL)
            );
        });
    }

    public function testNonApiUrlsAreNotRewritten(): void
    {
        // the proxy rewrites asset URLs in the responses it serves; anything
        // that is not an api.github.com URL spc wrote itself must pass through
        $this->withProxy('http://10.0.0.1:8080', function (): void {
            foreach ([
                'https://github.com/llvm/llvm-project/releases/download/llvmorg-20.1.0/clang.tar.xz',
                'http://10.0.0.1:8080/download/static-php/package-bin/v1/zlib.txz',
                'https://api.bitbucket.org/2.0/repositories/x/refs/tags',
            ] as $url) {
                $this->assertSame($url, GitHubRelease::getGitHubApiUrl($url));
            }
        });
    }

    private function withProxy(?string $value, callable $fn): void
    {
        $original = getenv('GHREL_PROXY');
        $value === null ? putenv('GHREL_PROXY') : putenv("GHREL_PROXY={$value}");

        try {
            $fn();
        } finally {
            $original === false ? putenv('GHREL_PROXY') : putenv("GHREL_PROXY={$original}");
        }
    }
}
