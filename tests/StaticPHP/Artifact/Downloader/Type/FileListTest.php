<?php

declare(strict_types=1);

namespace Tests\StaticPHP\Artifact\Downloader\Type;

use PHPUnit\Framework\TestCase;
use StaticPHP\Artifact\Downloader\Type\FileList;
use StaticPHP\Exception\DownloaderException;

/**
 * @internal
 */
class FileListTest extends TestCase
{
    private const array CONFIG = [
        'url' => 'https://example.com/source/',
        'regex' => '/href="(?<file>example-(?<version>[\d.]+)\.tar\.gz)"/',
    ];

    public function testParseFileListReturnsLatestVersion(): void
    {
        $page = '<a href="example-1.0.1.tar.gz"></a><a href="example-1.2.0.tar.gz"></a><a href="example-1.10.0.tar.gz"></a>';

        [$filename, $version, $versions] = $this->parse(self::CONFIG, $page);

        $this->assertSame('example-1.10.0.tar.gz', $filename);
        $this->assertSame('1.10.0', $version);
        $this->assertCount(3, $versions);
    }

    public function testParseFileListThrowsWhenRegexMatchesNothing(): void
    {
        // absolute hrefs defeat a regex anchored to href="<filename>"
        $page = '<a href="https://example.org/dl/example-1.0.1.tar.gz"></a>';

        $this->expectException(DownloaderException::class);
        $this->expectExceptionMessage('Failed to get example file list from https://example.com/source/: regex matched no files');
        $this->parse(self::CONFIG, $page);
    }

    public function testParseFileListThrowsOnEmptyPage(): void
    {
        $this->expectException(DownloaderException::class);
        $this->expectExceptionMessage('Failed to get example file list from https://example.com/source/: regex matched no files');
        $this->parse(self::CONFIG, '');
    }

    public function testParseFileListThrowsWhenAllMatchesArePreReleases(): void
    {
        $config = [
            'url' => 'https://example.com/source/',
            'regex' => '/href="(?<file>example-(?<version>[\w.-]+)\.tar\.gz)"/',
        ];
        $page = '<a href="example-1.0.0-rc1.tar.gz"></a><a href="example-1.0.0-beta2.tar.gz"></a>';

        $this->expectException(DownloaderException::class);
        $this->expectExceptionMessage('Failed to get example file list from https://example.com/source/: all matched versions were filtered out as pre-releases');
        $this->parse($config, $page);
    }

    private function parse(array $config, string $page): array
    {
        $method = new \ReflectionMethod(FileList::class, 'parseFileList');
        return $method->invoke(new FileList(), 'example', $config, $page);
    }
}
