<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Php\Process;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('install', 'Load the ports in PHP-FPM (and the CLI): check FPM can load the extension, write its ini line')]
final class InstallCommand extends Base
{
    protected function configure(): void
    {
        $this->addOption('fpm', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The php-fpm binary (default: every one found)')
            ->addOption('cli', null, InputOption::VALUE_NONE, 'Also load it in the command-line PHP')
            ->addOption('no-fpm', null, InputOption::VALUE_NONE, 'Leave PHP-FPM alone (with --cli: the command-line PHP only)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write the ini files (otherwise only show what would be written)')
            ->addOption('copy', null, InputOption::VALUE_NONE, 'Copy the extension into each PHP\'s extension_dir and load it from there')
            ->addOption('ini-name', null, InputOption::VALUE_REQUIRED, 'Name of the ini file in the conf.d directory', '99-fastphp.ini')
            ->setHelp(<<<'TXT'
                For every php-fpm it finds (or --fpm), checks the extension was built for that PHP
                (version, thread safety, module API) and that it loads, then shows or writes
                conf.d/99-fastphp.ini with its extension= line. Restart FPM afterwards.

                The extension is only a speed-up: the prologue in the source calls a port only when
                the extension is loaded. Deploy the patched source as usual; a server without the
                extension runs the original PHP.
                TXT);
    }

    protected function handle(InputInterface $in): int
    {
        $so = $this->project->path('build', Cargo::libName('fastphp_app'));
        if (!is_file($so)) {
            throw new \RuntimeException('No port is enabled, so there is no extension to install. Run `fast-php enable <id>` first.');
        }
        $built = $this->info([$this->php->binary]);
        $module = $this->module($so);
        $this->io->writeln(sprintf('Extension: %s (module "%s", built for PHP %s, %s)', $so, $module, $built['version'], $built['build']));

        $targets = [];
        foreach ($in->getOption('no-fpm') ? [] : ($in->getOption('fpm') ?: $this->findFpm()) as $fpm) {
            $targets[] = ['FPM', $fpm];
        }
        if ($in->getOption('cli')) {
            $targets[] = ['CLI', $this->php->binary];
        }
        if (!$targets) {
            $this->io->warning('No php-fpm found. Give it with --fpm /path/to/php-fpm, or use --cli for the command-line PHP.');
            return self::FAILURE;
        }

        $problems = 0;
        $restart = [];
        $written = [];
        foreach ($targets as [$kind, $bin]) {
            $this->io->section("$kind: $bin");
            $info = $this->info([$bin]);
            if ($info['scan_dir'] !== '' && isset($written[$info['scan_dir']])) {
                // Homebrew's CLI and FPM read the same conf.d.
                $this->io->writeln("  shares {$info['scan_dir']} with {$written[$info['scan_dir']]}: the same ini line loads it here too");
                continue;
            }
            $written[$info['scan_dir']] = "$kind ($bin)";
            if ($info['build'] !== $built['build']) {
                $this->io->writeln(sprintf('  <error>✗</error> This is PHP %s (%s); the extension is built for %s (%s). PHP only loads extensions built for its own version and thread safety.', $info['version'], $info['build'], $built['version'], $built['build']));
                $this->io->writeln("    Build with that PHP: FAST_PHP_BINARY=<its php> and its php-config first in PATH, then `fast-php enable` again.");
                $problems++;
                continue;
            }
            $path = $so;
            $target = $info['extension_dir'] !== '' ? $info['extension_dir'] . '/' . basename($so) : null;
            if ($in->getOption('copy')) {
                if ($target === null) {
                    $this->io->writeln('  <error>✗</error> This PHP reports no extension_dir to copy to.');
                    $problems++;
                    continue;
                }
                $path = $target;
            }
            // Load it the way that PHP will: same binary, no ini, just this extension.
            $p = Process::run([$bin, '-n', '-d', 'display_startup_errors=1', '-d', 'extension=' . $so, '-m']);
            if (!preg_match('/^' . preg_quote($module, '/') . '$/mi', $p->stdout)) {
                $this->io->writeln("  <error>✗</error> It doesn't load:\n    " . trim($p->stderr ?: $p->stdout));
                $problems++;
                continue;
            }
            $this->io->writeln("  <info>✓</info> PHP {$info['version']} ({$info['build']}) loads it");
            if ($info['scan_dir'] === '') {
                $this->io->writeln("  <error>✗</error> This PHP has no conf.d directory. Add to {$info['ini']}:\n    extension=$path");
                $problems++;
                continue;
            }
            $ini = $info['scan_dir'] . '/' . $in->getOption('ini-name');
            $content = "; fast-php: Rust ports of this app's hot functions. Remove this file to run plain PHP.\nextension=$path\n";
            if (!$in->getOption('write')) {
                $this->io->writeln("  would write $ini:");
                $this->io->writeln('    ' . str_replace("\n", "\n    ", trim($content)));
                if ($in->getOption('copy')) {
                    $this->io->writeln("  and copy the extension to $target");
                }
            } else {
                $wrote = true;
                if ($in->getOption('copy')) {
                    $wrote = @copy($so, "$target.tmp") && @rename("$target.tmp", $target);
                }
                $wrote = $wrote && @file_put_contents($ini, $content) !== false;
                if ($wrote) {
                    $this->io->writeln("  <info>✓</info> wrote $ini");
                } else {
                    $this->io->writeln("  <comment>!</comment> No permission to write there. Run:");
                    if ($in->getOption('copy')) {
                        $this->io->writeln('    sudo cp ' . escapeshellarg($so) . ' ' . escapeshellarg($target));
                    }
                    $this->io->writeln('    printf ' . escapeshellarg(str_replace('%', '%%', $content)) . ' | sudo tee ' . escapeshellarg($ini) . ' > /dev/null');
                    $problems++;
                }
            }
            if ($kind === 'FPM') {
                $restart[] = $this->restartHint($bin, $info['version']);
            }
        }

        if ($restart) {
            $this->io->section('Then');
            $this->io->listing([
                'Restart FPM so its master process loads the extension: ' . implode(', or ', array_unique($restart)) . '.',
                'Deploy the source with the fast-php prologues as usual (with opcache.validate_timestamps=0, reset opcache as on any deploy).',
                'Check it: vendor/bin/fast-php bench --url http://your-app --label after (after a run with --label before, without the extension).',
                'To switch the ports off without removing anything: set FAST_PHP_DISABLE=1 in the environment FPM starts with (systemd: Environment=FAST_PHP_DISABLE=1) and restart it.',
                $in->getOption('copy') ? 'The copy in extension_dir is what FPM loads: run `fast-php install --copy --write` again after enabling more ports.'
                    : 'FPM loads the extension from .fast-php/build; `fast-php enable` replaces it there, and FPM picks the new one up on its next restart.',
            ]);
        }
        if ($problems) {
            $this->io->error($problems === 1 ? 'One target needs attention.' : "$problems targets need attention.");
            return self::FAILURE;
        }
        $this->io->success($in->getOption('write') ? 'Installed.' : 'Everything can load it. Run again with --write to write the ini files.');
        return self::SUCCESS;
    }

    /**
     * @param list<string> $cmd
     * @return array{version: string, build: string, scan_dir: string, extension_dir: string, ini: string}
     */
    private function info(array $cmd): array
    {
        $text = Process::run([...$cmd, '-i'])->stdout;
        $get = static function (string $key) use ($text): string {
            if (!preg_match('/^' . preg_quote($key, '/') . ' => (.*?)(?: => .*)?$/m', $text, $m)) {
                return '';
            }
            return trim($m[1]) === '(none)' ? '' : trim($m[1]);
        };
        if ($get('PHP Version') === '') {
            throw new \RuntimeException('Can\'t run ' . implode(' ', $cmd) . ' -i.');
        }
        return ['version' => $get('PHP Version'), 'build' => $get('PHP Extension Build'), 'scan_dir' => $get('Scan this dir for additional .ini files'),
            'extension_dir' => $get('extension_dir'), 'ini' => $get('Loaded Configuration File')];
    }

    /** The name PHP knows the extension by (what `php -m` lists). */
    private function module(string $so): string
    {
        $probe = 'echo json_encode(get_loaded_extensions());';
        $without = json_decode(Process::run([$this->php->binary, '-n', '-r', $probe])->stdout, true) ?: [];
        $with = json_decode(Process::run([$this->php->binary, '-n', '-d', 'extension=' . $so, '-r', $probe])->stdout, true) ?: [];
        $new = array_values(array_diff($with, $without));
        return $new[0] ?? throw new \RuntimeException("$so doesn't load in {$this->php->binary}. Run `fast-php enable` again to rebuild it.");
    }

    /** @return list<string> */
    private function findFpm(): array
    {
        $found = [];
        $names = ['php-fpm', ...array_map(static fn ($v) => "php-fpm$v", ['8.5', '8.4', '8.3', '8.2'])];
        $dirs = [...explode(PATH_SEPARATOR, (string) getenv('PATH')), '/usr/sbin', '/usr/local/sbin', '/opt/homebrew/sbin'];
        foreach ($dirs as $dir) {
            foreach ($names as $n) {
                $p = rtrim($dir, '/') . '/' . $n;
                if (is_file($p) && is_executable($p)) {
                    $found[(string) realpath($p)] = $p;
                }
            }
        }
        return array_values($found);
    }

    private function restartHint(string $bin, string $version): string
    {
        if (str_contains((string) realpath($bin), '/Cellar/') || str_starts_with($bin, '/opt/homebrew/')) {
            return 'brew services restart php' . (preg_match('/php@(\d\.\d)/', (string) realpath($bin), $m) ? "@{$m[1]}" : '');
        }
        $short = implode('.', array_slice(explode('.', $version), 0, 2));
        return is_dir('/etc/php/' . $short) ? "sudo systemctl restart php$short-fpm" : 'sudo systemctl restart php-fpm';
    }
}
