<?php
declare(strict_types=1);

namespace FastPhp\Cli;

use Symfony\Component\Console\Application as Console;

final class Application extends Console
{
    public const VERSION = '0.1.0-dev';

    public function __construct()
    {
        parent::__construct('fast-php', self::VERSION);
        $this->addCommands([
            new DoctorCommand(),
            new ScanCommand(),
            new PortCommand(),
            new VerifyCommand(),
            new EnableCommand(),
            new DisableCommand(),
            new BenchCommand(),
            new StatusCommand(),
            new InstallCommand(),
        ]);
    }
}
