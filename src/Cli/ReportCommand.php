<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Report\Report;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('report', 'Write report.md and report.svg: the scan, every port and the benchmark')]
final class ReportCommand extends Base
{
    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Directory to write report.md and report.svg to (default: .fast-php)');
    }

    protected function handle(InputInterface $in): int
    {
        if (!isset($this->project->state['scan'])) {
            throw new \RuntimeException('Nothing to report yet. Run `fast-php scan` first.');
        }
        $dir = $in->getOption('out') !== null ? $this->project->abs($in->getOption('out')) : $this->project->path();
        @mkdir($dir, 0777, true);
        $report = new Report($this->project->state, $this->project->workload()->describe());
        file_put_contents("$dir/report.md", $report->markdown());
        file_put_contents("$dir/report.svg", $report->svg());
        $this->io->writeln(sprintf('Wrote %s/report.md and report.svg', $this->project->rel($dir)));
        return self::SUCCESS;
    }
}
