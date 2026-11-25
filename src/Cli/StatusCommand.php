<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand('status', 'Show the last scan and every port')]
final class StatusCommand extends Base
{
    protected function handle(InputInterface $in): int
    {
        $s = $this->project->state;
        if (!isset($s['scan'])) {
            $this->io->writeln('Nothing yet. Start with <info>vendor/bin/fast-php scan -- php your-script.php</info>');
            return self::SUCCESS;
        }
        $w = $s['workload'];
        $this->io->writeln(sprintf('Workload: php %s %s', $w['script'], implode(' ', $w['args'])));
        $this->io->writeln(sprintf('Last scan: %s, JIT off %s, JIT on %s', $s['scan']['php'], self::ms($s['scan']['jitOff']), self::ms($s['scan']['jitOn'])));
        $rows = [];
        foreach ($s['ports'] ?? [] as $id => $p) {
            $v = $p['verify'] ?? null;
            $rows[] = [
                $id,
                $p['status'] . ($p['enabled'] ? ', enabled' : ''),
                $p['recorded'] . ' + ' . $p['derived'],
                $v ? "{$v['passed']}/" . ($v['total'] - $v['fellBack']) : '-',
                $v && $v['php'] ? sprintf('%.1f×', $v['php'] / $v['native']) : '-',
            ];
        }
        if ($rows) {
            $this->io->table(['port', 'status', 'cases (recorded + derived)', 'verified', 'per call'], $rows);
        }
        if (isset($s['bench'])) {
            $b = $s['bench'];
            $this->io->writeln(sprintf('Last bench: %s → %s (%.1f×)%s', self::ms($b['php']), self::ms($b['native']), $b['php'] / $b['native'], $b['identical'] ? ', identical output' : ', OUTPUT DIFFERED'));
        }
        return self::SUCCESS;
    }
}
