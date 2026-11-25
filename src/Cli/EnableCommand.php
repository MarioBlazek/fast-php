<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Patch\Patcher;
use FastPhp\Port\Scaffolder;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('enable', 'Swap a verified port in: add the prologue and build the app extension')]
final class EnableCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Port id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Enable it even if it is not verified');
    }

    /** The line that hands a call to the native function when the extension is loaded. */
    public static function prologue(array $port): string
    {
        $native = $port['native'];
        $quoted = var_export($native, true);
        $args = implode(', ', array_map(static fn ($p) => '$' . $p['name'], $port['params']));
        return $port['fallback']
            ? "if (\\function_exists($quoted) && null !== \$__fastphp = \\$native($args)) return \$__fastphp;"
            : "if (\\function_exists($quoted)) return \\$native($args);";
    }

    protected function handle(InputInterface $in): int
    {
        $port = $this->project->port($in->getArgument('id'));
        $id = $port['id'];
        if ($port['status'] !== 'verified' && !$in->getOption('force')) {
            throw new \RuntimeException("$id isn't verified (status: {$port['status']}). Run `fast-php verify $id` first.");
        }
        $this->patch($port['file'], static fn (string $code) => Patcher::insert($code, $port['class'], $port['function'], $id, self::prologue($port)));
        $this->project->state['ports'][$id]['enabled'] = true;
        $this->project->save();
        $so = self::build($this, $this->project, $this->cargo);
        $this->io->success("$id is enabled in {$port['file']}.");
        $this->io->writeln([
            'The prologue only calls the port when the extension is loaded, so the app runs unchanged without it:',
            '',
            "  php -d extension=$so " . $this->project->workload()['script'],
            '',
            'For PHP-FPM, add that extension= line to a conf.d ini and restart FPM. FAST_PHP_DISABLE=1 turns the ports off.',
            "Next: <info>vendor/bin/fast-php bench</info>",
        ]);
        return self::SUCCESS;
    }

    /** Rebuilds .fast-php/build/fastphp_app with every enabled port. Returns its path, or '' when none is enabled. */
    public static function build(Base $cmd, \FastPhp\Project $project, Cargo $cargo): string
    {
        $ids = array_keys(array_filter($project->state['ports'] ?? [], static fn ($p) => $p['enabled']));
        $scaffolder = new Scaffolder($project);
        $scaffolder->workspace();
        $scaffolder->ext($ids);
        $out = $project->path('build', Cargo::libName('fastphp_app'));
        if (!$ids) {
            @unlink($out);
            return '';
        }
        $built = $cargo->package('fastphp_app');
        @mkdir(dirname($out), 0777, true);
        // Copy to a new file and rename, so a running PHP that has the old one mapped isn't disturbed.
        copy($built, "$out.tmp");
        rename("$out.tmp", $out);
        return $out;
    }
}
