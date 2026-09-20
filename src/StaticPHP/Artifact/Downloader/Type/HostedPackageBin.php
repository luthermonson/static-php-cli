<?php

declare(strict_types=1);

namespace StaticPHP\Artifact\Downloader\Type;

use StaticPHP\Artifact\ArtifactDownloader;
use StaticPHP\Artifact\Downloader\DownloadResult;
use StaticPHP\Exception\DownloaderException;
use StaticPHP\Runtime\SystemTarget;

class HostedPackageBin implements DownloadTypeInterface
{
    use GitHubTokenSetupTrait;

    public const string BASE_REPO = 'static-php/package-bin';

    public const array ASSET_MATCHES = [
        'linux' => '{name}-{arch}-{os}-{libc}-{libcver}.txz',
        'darwin' => '{name}-{arch}-{os}.txz',
        'windows' => '{name}-{arch}-{os}.tgz',
    ];

    private static array $release_info = [];

    /**
     * Repository that hosts the prebuilt package assets.
     *
     * Defaults to BASE_REPO, so an unconfigured spc behaves exactly as it
     * always has. SPC_PACKAGE_BIN_REPO lets a downstream publish its own
     * prebuilts without forking this file: the asset naming, libc/arch
     * resolution, extraction and install paths are all worth reusing, and the
     * only thing that actually needs to change is where the assets live.
     */
    public static function getRepo(): string
    {
        $repo = getenv('SPC_PACKAGE_BIN_REPO');
        return is_string($repo) && trim($repo) !== '' ? trim($repo) : self::BASE_REPO;
    }

    /**
     * Release tag to take assets from, or null to use the newest release.
     *
     * Upstream publishes a repo whose every release IS a package-bin release,
     * so "newest" is always right there. A downstream reusing an existing
     * repository cannot assume that -- its newest release is whatever it last
     * shipped -- so SPC_PACKAGE_BIN_TAG pins one dedicated tag to read from,
     * the same way a project might keep mirrored sources on a fixed tag.
     */
    public static function getTag(): ?string
    {
        $tag = getenv('SPC_PACKAGE_BIN_TAG');
        return is_string($tag) && trim($tag) !== '' ? trim($tag) : null;
    }

    public static function getReleaseInfo(): array
    {
        if (empty(self::$release_info)) {
            $repo = self::getRepo();
            $tag = self::getTag();
            if ($tag !== null) {
                // Fetch the one tag directly; see getGitHubReleaseByTag for why
                // filtering a listing would be wrong here.
                self::$release_info = new GitHubRelease()->getGitHubReleaseByTag('hosted', $repo, $tag);
            } else {
                $rel = new GitHubRelease()->getGitHubReleases('hosted', $repo);
                if (empty($rel)) {
                    throw new DownloaderException("No releases found for hosted package-bin in {$repo}");
                }
                self::$release_info = $rel[0];
            }
        }
        return self::$release_info;
    }

    /**
     * Drops the cached release lookup. Only needed by tests, which otherwise
     * leak one test's repo/tag into the next through the static cache.
     */
    public static function resetReleaseInfo(): void
    {
        self::$release_info = [];
    }

    public function download(string $name, array $config, ArtifactDownloader $downloader): DownloadResult
    {
        $info = self::getReleaseInfo();
        $replace = [
            '{name}' => $name,
            '{arch}' => SystemTarget::getTargetArch(),
            '{os}' => strtolower(SystemTarget::getTargetOS()),
            '{libc}' => SystemTarget::getLibc() ?? 'default',
            '{libcver}' => SystemTarget::getLibcVersion() ?? 'default',
        ];
        $find_str = str_replace(array_keys($replace), array_values($replace), self::ASSET_MATCHES[strtolower(SystemTarget::getTargetOS())]);
        foreach ($info['assets'] as $asset) {
            if ($asset['name'] === $find_str) {
                $download_url = $asset['browser_download_url'];
                $filename = $asset['name'];
                $version = ltrim($info['tag_name'], 'v');
                logger()->debug("Downloading hosted package-bin {$name} version {$version} from GitHub: {$download_url}");
                $path = DOWNLOAD_PATH . DIRECTORY_SEPARATOR . $filename;
                $headers = $this->getGitHubTokenHeaders();
                default_shell()->executeCurlDownload($download_url, $path, headers: $headers, retries: $downloader->getRetry());
                return DownloadResult::archive($filename, $config, extract: $config['extract'] ?? null, version: $version, downloader: static::class);
            }
        }
        // Fails the binary queue item, which falls through to the source
        // download and a normal from-source build. That is deliberate: a
        // missing prebuilt should cost build time, never a broken build.
        throw new DownloaderException(sprintf(
            'No matching asset found for hosted package-bin %s: %s (repo %s, release %s)',
            $name,
            $find_str,
            self::getRepo(),
            $info['tag_name'] ?? 'unknown'
        ));
    }
}
