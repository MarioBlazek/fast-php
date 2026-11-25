<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use FastPhp\Patch\Patcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand('disable', 'Take a port out: remove its prologue byte for byte and rebuild the app extension')]
final class DisableCommand extends Base
{
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Port id')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Disable every port');
    }

    protected function handle(InputInterface $in): int
    {
        $ids = $in->getOption('all')
            ? array_keys(array_filter($this->project->state['ports'] ?? [], static fn ($p) => $p['enabled']))
            : [$this->project->port((string) $in->getArgument('id'))['id']];
        foreach ($ids as $id) {
            $port = $this->project->port($id);
            $path = $this->project->abs($port['file']);
            if (is_file($path) && Patcher::has((string) file_get_contents($path), $id)) {
                $this->patch($port['file'], static fn (string $code) => Patcher::strip($code, $id));
            }
            $this->project->state['ports'][$id]['enabled'] = false;
            $this->project->save();
            $this->io->writeln("Disabled $id: {$port['file']} is back to its original bytes.");
        }
        $so = EnableCommand::build($this, $this->project, $this->cargo);
        $this->io->success($so === '' ? 'No port is enabled.' : "Rebuilt $so.");
        return self::SUCCESS;
    }
}
