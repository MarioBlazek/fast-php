<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Analysis\Codebase;
use FastPhp\Analysis\Func;
use FastPhp\Analysis\Ranker;
use FastPhp\Patch\Patcher;
use FastPhp\Port\Cases;
use FastPhp\Port\Scaffolder;
use FastPhp\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('port', 'Record real calls of a function and set up its Rust crate (port.rs, glue, TASK.md)')]
final class PortCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Function id from `fast-php scan`, e.g. Fuzzy_findDuplicates')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Recorded calls to keep', '500')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Use this file as port.rs (a port written beforehand)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Port it even though the scan found blockers');
    }

    /** @return array{Codebase, Func} */
    public static function locate(Project $project, string $id): array
    {
        $hot = $project->hotspot($id);
        $files = array_values(array_unique(array_map(static fn ($r) => $project->abs($r['file']), $project->state['scan']['functions'])));
        $cb = Codebase::load($files, \FastPhp\Analysis\ComposerMap::forProject($project->root));
        foreach ($cb->funcs as $f) {
            if ($f->id() === $hot['id']) {
                return [$cb, $f];
            }
        }
        throw new \RuntimeException("{$hot['name']} is no longer in {$hot['file']}. Run the scan again.");
    }

    /** @var list<string> properties an instance method reads */
    private array $props = [];

    protected function handle(InputInterface $in): int
    {
        [$cb, $f] = self::locate($this->project, $in->getArgument('id'));
        $this->props = $f->static ? [] : $cb->props($f);
        $id = $f->id();
        $why = Ranker::blockers($cb, $f);
        if ($why && !$in->getOption('force')) {
            throw new \RuntimeException("{$f->display()} can't be ported as it is:\n - " . implode("\n - ", $why));
        }
        $reference = $in->getOption('reference');
        if ($reference !== null && !is_file($reference)) {
            throw new \RuntimeException("No file $reference.");
        }

        $this->io->section("Recording calls of {$f->display()}");
        $dir = $this->project->path('ports', $id);
        @mkdir($dir, 0777, true);
        $cases = "$dir/cases.jsonl";
        $stats = $this->record($f, $cases, max(1, (int) $in->getOption('max')));
        $this->io->writeln(sprintf('  %d recorded calls, %d derived edge cases (answered by the original PHP)', $stats['recorded'], $stats['derived']));
        if ($stats['recorded'] === 0) {
            throw new \RuntimeException("The workload never called {$f->display()}.");
        }

        $this->io->section('Setting up the crate');
        $scaffolder = new Scaffolder($this->project);
        $scaffolder->workspace();
        $info = $scaffolder->port($cb, $f, Cases::read($cases), $reference);
        $old = $this->project->state['ports'][$id] ?? [];
        $this->project->state['ports'][$id] = $info + [
            'recorded' => $stats['recorded'],
            'derived' => $stats['derived'],
            'status' => 'ported',
            'enabled' => $old['enabled'] ?? false,
        ];
        $this->project->save();

        $rel = $this->project->rel($dir);
        $sig = implode(', ', array_map(static fn ($p) => "{$p['name']}: {$p['rust']}", $info['params']));
        $this->io->listing([
            "$rel/src/port.rs  " . ($reference !== null ? "copied from $reference" : 'write the port here'),
            "$rel/TASK.md  the original, its helpers, PHP semantics to match, sample calls",
            "$rel/src/glue.rs  generated: " . Scaffolder::native($id) . "($sig) -> {$info['returns']}" . ($info['fallback'] ? ', null = let PHP run' : ''),
        ]);
        $this->io->writeln($reference !== null
            ? "Next: <info>vendor/bin/fast-php verify $id</info>"
            : "Next: have your coding agent follow $rel/TASK.md, then <info>vendor/bin/fast-php verify $id</info>");
        return self::SUCCESS;
    }

    /** @return array{recorded: int, derived: int} */
    private function record(Func $f, string $into, int $max): array
    {
        $file = $this->project->rel($f->file);
        $marker = 'rec:' . $f->id();
        $id = var_export($f->id(), true);
        $args = '[' . implode(', ', array_map(static fn ($p) => '$' . $p['name'], $f->params)) . ']';
        if ($f->static) {
            $target = $f->class !== null ? "self::{$f->name}" : '\\' . $f->name;
            $call = "static fn (...\$__fastphp) => $target(...\$__fastphp), $args";
        } else {
            $props = implode(', ', array_map(static fn ($p) => "\$this->$p", $this->props));
            $call = "fn (...\$__fastphp) => \$this->{$f->name}(...\$__fastphp), $args, fn () => [$props]";
        }
        $prologue = "if (\\class_exists('FastPhp\\\\Runtime\\\\Rec', false) && \\FastPhp\\Runtime\\Rec::on($id)) return \\FastPhp\\Runtime\\Rec::call($id, $call);";

        $before = (string) file_get_contents($f->file);
        $this->project->state['recording'][$file] = ['marker' => $marker];
        $this->project->save();
        $shards = $this->project->path('tmp', 'rec');
        @mkdir($shards, 0777, true);
        array_map('unlink', glob("$shards/{rec-*.jsonl,derived-*}", GLOB_BRACE) ?: []);
        try {
            $this->patch($file, static fn (string $code) => Patcher::insert($code, $f->class, $f->name, $marker, $prologue));
            $this->project->workload()->once($this->php, $this->project, [], false,
                ['-d', 'auto_prepend_file=' . Project::toolRoot() . '/res/rec.php', '-d', 'memory_limit=-1'],
                ['FASTPHP_REC_DIR' => $shards, 'FASTPHP_REC_MAX' => (string) $max]);
        } finally {
            file_put_contents($f->file, Patcher::strip((string) file_get_contents($f->file), $marker));
            unset($this->project->state['recording'][$file]);
            $this->project->save();
        }
        if (file_get_contents($f->file) !== $before) {
            throw new \RuntimeException("$file is not byte-for-byte what it was before recording. Check `git diff $file`.");
        }
        return Cases::merge(glob("$shards/rec-*.jsonl") ?: [], $f->id(), $into, $max);
    }
}
