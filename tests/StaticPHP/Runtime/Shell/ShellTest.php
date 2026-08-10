<?php

declare(strict_types=1);

namespace Tests\StaticPHP\Runtime\Shell;

use PHPUnit\Framework\TestCase;
use StaticPHP\Runtime\Shell\DefaultShell;

/**
 * @internal
 */
class ShellTest extends TestCase
{
    /**
     * Captured output must be complete even when the child writes more data
     * than fits in the descriptor buffers shortly before exiting. On Windows
     * the stdout/stderr descriptors are non-blocking socket pairs, and the
     * post-exit drain must read to real EOF instead of stopping at the first
     * momentarily-empty read.
     */
    public function testPassthruCapturesLargeOutputCompletely(): void
    {
        $length = 600000;
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spc_shell_test_' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($script, "<?php echo str_repeat('a', {$length});");

        try {
            $shell = new DefaultShell(false, false);
            $method = new \ReflectionMethod($shell, 'passthru');
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);

            $result = $method->invoke($shell, $cmd, false, null, true, false);

            $this->assertSame(0, $result['code']);
            $this->assertSame($length, strlen($result['output']));
            $this->assertSame(str_repeat('a', $length), $result['output']);
        } finally {
            @unlink($script);
        }
    }
}
