<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Patch\Patcher;
use FastPhp\Php\Php;
use FastPhp\Project;
use FastPhp\Rust\Cargo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class Base extends Command
{
    protected Project $project;
    protected Php $php;
    protected Cargo $cargo;
    protected SymfonyStyle $io;

    abstract protected function handle(InputInterface $in): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->project = Project::here();
        $this->php = new Php(getenv('FAST_PHP_BINARY') ?: PHP_BINARY);
        $this->cargo = new Cargo($this->project);
        $this->restoreLeftovers();
        try {
            return $this->handle($input);
        } catch (\RuntimeException $e) {
            $this->io->error($e->getMessage());
            return self::FAILURE;
        }
    }

    /** A recording run that was killed can leave its prologue in the source: take it out. */
    private function restoreLeftovers(): void
    {
        foreach ($this->project->state['recording'] ?? [] as $file => $r) {
            $path = $this->project->abs($file);
            if (is_file($path)) {
                $code = Patcher::strip((string) file_get_contents($path), $r['marker']);
                file_put_contents($path, $code);
                $this->io->note("Removed a recording prologue a previous run left in $file.");
            }
            unset($this->project->state['recording'][$file]);
            $this->project->save();
        }
    }

    /** Patches $file through $edit and checks the result parses; hash checks are the caller's. */
    protected function patch(string $file, callable $edit): void
    {
        $path = $this->project->abs($file);
        $code = (string) file_get_contents($path);
        $new = $edit($code);
        file_put_contents($path, $new);
        $lint = \FastPhp\Php\Process::run([$this->php->binary, '-n', '-l', $path]);
        if ($lint->exit !== 0) {
            file_put_contents($path, $code);
            throw new \RuntimeException("Patching $file broke it, so it was restored:\n" . trim($lint->stdout . $lint->stderr));
        }
    }

    protected static function ms(float $s): string
    {
        return match (true) {
            $s >= 1 => sprintf('%.2f s', $s),
            $s >= 1e-3 => sprintf('%.1f ms', $s * 1e3),
            $s >= 1e-6 => sprintf('%.2f µs', $s * 1e6),
            default => sprintf('%.0f ns', $s * 1e9),
        };
    }

    protected static function pct(float $x): string
    {
        return sprintf('%.1f%%', $x * 100);
    }

    /** The $p quantile (0..1) of $values, nearest rank. @param list<float> $values */
    protected static function quantile(array $values, float $p): float
    {
        sort($values);
        return $values[max(0, min(count($values) - 1, (int) ceil($p * count($values)) - 1))];
    }

    /** @param list<float> $latencies */
    protected static function latency(array $latencies): string
    {
        $q = static fn (float $p) => self::quantile($latencies, $p);
        return sprintf('per request: p50 %s, p95 %s', self::ms($q(0.5)), self::ms($q(0.95)));
    }
}
