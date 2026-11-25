<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Php\Php;
use FastPhp\Php\Process;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand('doctor', 'Check PHP, its headers, Rust and libclang are ready to build extensions')]
final class DoctorCommand extends Base
{
    private int $problems = 0;

    protected function handle(InputInterface $in): int
    {
        $php = $this->php;
        [$version, $zts] = explode(' ', $php->version()) + [1 => 'NTS'];
        $this->check(version_compare($version, '8.2.0', '>='), "PHP $version $zts", 'fast-php needs PHP 8.2 or newer.');
        if ($zts === 'ZTS') {
            $this->io->writeln('  <comment>!</comment> ZTS builds are not tested yet.');
        }

        $cfg = $this->which('php-config');
        $cfgVersion = $cfg ? trim(Process::run([$cfg, '--version'])->stdout) : '';
        $this->check($cfg !== null && $cfgVersion === $version, $cfg ? "php-config $cfgVersion ($cfg)" : 'php-config',
            $cfg === null
                ? 'php-config is missing: install PHP\'s development package (Debian/Ubuntu: php' . substr($version, 0, 3) . '-dev; macOS: brew install php).'
                : "php-config is for PHP $cfgVersion but php is $version. Put the matching php-config first in PATH: extensions are built against its headers.");

        $rustc = $this->which('rustc');
        $rv = $rustc ? trim(Process::run([$rustc, '--version'])->stdout) : '';
        preg_match('/(\d+\.\d+)/', $rv, $m);
        $this->check($rustc !== null && version_compare($m[1] ?? '0', '1.85', '>='), $rv ?: 'Rust', 'Install Rust 1.85 or newer from https://rustup.rs');
        $this->check($this->which('cargo') !== null, 'cargo', 'cargo is missing; install Rust from https://rustup.rs');

        $this->libclang();

        $skew = $php->skewing();
        if ($skew) {
            $this->io->writeln('  <comment>!</comment> php.ini loads ' . implode(', ', $skew) . '. fast-php runs PHP with -n so it is left out of every measurement; keep it off in production too.');
        }
        $jit = Process::run([...$php->base(), ...Php::JIT, '-r', 'echo json_encode(function_exists("opcache_get_status") ? (opcache_get_status(false)["jit"]["on"] ?? false) : false);']);
        $this->check(trim($jit->stdout) === 'true', 'opcache JIT', 'The JIT doesn\'t turn on with opcache.jit=tracing. fast-php can still work, but its baseline would be slower than your production.');

        if ($this->problems === 0) {
            $this->io->success('Ready.');
            return self::SUCCESS;
        }
        $this->io->error($this->problems === 1 ? 'One problem to fix.' : "{$this->problems} problems to fix.");
        return self::FAILURE;
    }

    private function libclang(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $clang = trim(Process::run(['xcrun', '--find', 'clang'])->stdout);
            $this->check($clang !== '', 'libclang (Xcode command line tools)', 'Run: xcode-select --install');
            return;
        }
        if (getenv('LIBCLANG_PATH') !== false) {
            $this->check(glob(getenv('LIBCLANG_PATH') . '/libclang*') !== [], 'libclang in LIBCLANG_PATH=' . getenv('LIBCLANG_PATH'), 'LIBCLANG_PATH has no libclang in it.');
            if (getenv('CLANG_PATH') === false) {
                $this->io->writeln('  <comment>!</comment> LIBCLANG_PATH is set but CLANG_PATH isn\'t. If several LLVMs are installed, set CLANG_PATH to the clang next to that libclang, or bindgen may mix headers.');
            }
            return;
        }
        $env = Cargo::env();
        $all = glob('/usr/lib/llvm-*', GLOB_ONLYDIR) ?: [];
        $this->check($env !== [] || glob('/usr/lib/*/libclang*.so*') !== [], $env ? "libclang ({$env['LIBCLANG_PATH']})" : 'libclang',
            'Install libclang (Debian/Ubuntu: apt install libclang-dev clang).');
        if (count($all) > 1 && $env) {
            $this->io->writeln('  <comment>!</comment> Several LLVMs are installed (' . implode(', ', array_map('basename', $all)) . "). fast-php builds with LIBCLANG_PATH={$env['LIBCLANG_PATH']} CLANG_PATH={$env['CLANG_PATH']}; set those yourself to use cargo directly.");
        }
    }

    private function check(bool $ok, string $what, string $fix): void
    {
        if ($ok) {
            $this->io->writeln("  <info>✓</info> $what");
            return;
        }
        $this->problems++;
        $this->io->writeln("  <error>✗</error> $what\n    $fix");
    }

    private function which(string $bin): ?string
    {
        $p = Process::run(['sh', '-c', 'command -v "$0"', $bin]);
        return $p->exit === 0 && trim($p->stdout) !== '' ? trim($p->stdout) : null;
    }
}
