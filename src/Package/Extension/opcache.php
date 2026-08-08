<?php

declare(strict_types=1);

namespace Package\Extension;

use Package\Target\php;
use StaticPHP\Attribute\Package\BeforeStage;
use StaticPHP\Attribute\Package\CustomPhpConfigureArg;
use StaticPHP\Attribute\Package\Extension;
use StaticPHP\Attribute\Package\Validate;
use StaticPHP\Attribute\PatchDescription;
use StaticPHP\Exception\WrongUsageException;
use StaticPHP\Package\PackageBuilder;
use StaticPHP\Package\PackageInstaller;
use StaticPHP\Package\PhpExtensionPackage;
use StaticPHP\Runtime\SystemTarget;
use StaticPHP\Util\SourcePatcher;

#[Extension('opcache')]
class opcache extends PhpExtensionPackage
{
    #[Validate]
    public function validate(): void
    {
        if (php::getPHPVersionID() < 80000 && getenv('SPC_SKIP_PHP_VERSION_CHECK') !== 'yes') {
            throw new WrongUsageException('Statically compiled PHP with Zend Opcache only available for PHP >= 8.0 !');
        }
    }

    #[BeforeStage('php', [php::class, 'buildconfForUnix'], 'ext-opcache')]
    #[BeforeStage('php', [php::class, 'buildconfForWindows'], 'ext-opcache')]
    #[PatchDescription('Fix static opcache build for PHP 8.2.0 to 8.4.x')]
    public function patchBeforeBuildconf(PackageInstaller $installer): bool
    {
        $version = php::getPHPVersion();
        $php_src = $installer->getTargetPackage('php')->getSourceDir();

        // OPcache refuses to start under SAPI names outside its allowlist
        // (supported_sapis[] in ZendAccelerator.c) - embed-based runtimes get
        // "opcache_get_status() => false" even with opcache.enable=1, because
        // "embed" is not in the list. Inject the SAPI names the consumer needs
        // the same way frankenphp/ngx-php were added upstream.
        //
        // SPC_OPCACHE_EXTRA_SAPIS is a comma-separated list. Unset defaults to
        // "ephpm" (ePHPm builds predate this knob and must keep working
        // unchanged). Set to the empty string to inject nothing.
        //
        // PHP 8.5 removed the allowlist entirely (any SAPI may use OPcache;
        // only cli/phpdbg still gate on enable_cli), so the patch applies to
        // < 8.5 only - anchored on "fuzzer", which exists exactly once in
        // 8.0-8.4.
        if (php::getPHPVersionID() < 80500) {
            $accel = "{$php_src}/ext/opcache/ZendAccelerator.c";
            $code = @file_get_contents($accel);
            if ($code !== false) {
                $env = getenv('SPC_OPCACHE_EXTRA_SAPIS');
                $requested = $env === false
                    ? ['ephpm']
                    : array_filter(array_map('trim', explode(',', $env)), fn (string $name) => $name !== '');

                $inject = [];
                foreach ($requested as $name) {
                    // The name is spliced into a C string literal, so anything
                    // outside this set could break the source or inject code.
                    if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
                        throw new WrongUsageException("Invalid SAPI name in SPC_OPCACHE_EXTRA_SAPIS: '{$name}' (allowed: letters, digits, dot, dash, underscore)");
                    }
                    // Already in supported_sapis[] upstream (cli, fpm-fcgi,
                    // frankenphp, ...) or injected by an earlier run.
                    if (!str_contains($code, "\"{$name}\"")) {
                        $inject[] = $name;
                    }
                }

                if ($inject !== []) {
                    $literals = implode('', array_map(fn (string $name) => " \"{$name}\",", $inject));
                    $patched = str_replace('"fuzzer",', '"fuzzer",' . $literals, $code, $count);
                    if ($count !== 1) {
                        throw new WrongUsageException('OPcache SAPI allowlist patch failed: expected exactly one "fuzzer" anchor in ZendAccelerator.c, found ' . $count);
                    }
                    file_put_contents($accel, $patched);
                }
            }
        }

        if (file_exists("{$php_src}/.opcache_patched")) {
            return false;
        }
        // if 8.2.0 <= PHP_VERSION < 8.2.23, we need to patch from legacy patch file
        if (version_compare($version, '8.2.0', '>=') && version_compare($version, '8.2.23', '<')) {
            SourcePatcher::patchFile('spc_fix_static_opcache_before_80222.patch', $php_src);
        }
        // if 8.3.0 <= PHP_VERSION < 8.3.11, we need to patch from legacy patch file
        elseif (version_compare($version, '8.3.0', '>=') && version_compare($version, '8.3.11', '<')) {
            SourcePatcher::patchFile('spc_fix_static_opcache_before_80310.patch', $php_src);
        }
        // if 8.3.12 <= PHP_VERSION < 8.5.0-dev, we need to patch from legacy patch file
        elseif (version_compare($version, '8.5.0-dev', '<')) {
            SourcePatcher::patchPhpSrc(items: ['static_opcache']);
        }
        // PHP 8.5.0-dev and later supports static opcache without patching
        else {
            return false;
        }
        return file_put_contents($php_src . '/.opcache_patched', '1') !== false;
    }

    #[CustomPhpConfigureArg('Darwin')]
    #[CustomPhpConfigureArg('Linux')]
    public function getUnixConfigureArg(bool $shared, PackageBuilder $builder): string
    {
        $phpVersionID = php::getPHPVersionID();
        $opcache_jit = ' --enable-opcache-jit';
        if ((SystemTarget::getTargetOS() === 'Linux' &&
                SystemTarget::getLibc() === 'musl' &&
                $builder->getOption('enable-zts') &&
                SystemTarget::getTargetArch() === 'x86_64' &&
                $phpVersionID < 80500) ||
            $builder->getOption('disable-opcache-jit')
        ) {
            $opcache_jit = ' --disable-opcache-jit';
        }
        // PHP 8.5+ has opcache built-in
        if ($phpVersionID < 80500) {
            return '--enable-opcache' . ($shared ? '=shared' : '') . $opcache_jit;
        }
        return trim($opcache_jit);
    }
}
