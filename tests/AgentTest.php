<?php
declare(strict_types=1);

namespace FastPhp\Tests;

use FastPhp\Agent\Agent;
use FastPhp\Agent\Guard;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testClaudeMayEditAndRunOnlyVerify(): void
    {
        $argv = Agent::named('claude', 'opus')->argv('PROMPT', '/app', 'php /t/bin/fast-php verify X');
        self::assertSame(['claude', '-p', 'PROMPT', '--permission-mode', 'acceptEdits', '--output-format', 'text',
            '--allowedTools', 'Read,Edit,Write,Glob,Grep,Bash(php /t/bin/fast-php verify X:*)', '--model', 'opus'], $argv);
    }

    public function testCodexRunsInTheProjectSandboxed(): void
    {
        self::assertSame(['codex', 'exec', '-C', '/app', '--skip-git-repo-check', '-s', 'workspace-write', 'PROMPT'], Agent::named('codex')->argv('PROMPT', '/app', 'v'));
    }

    public function testAnyOtherAgentIsAShellCommand(): void
    {
        $a = Agent::named('aider --yes');
        self::assertSame(['sh', '-c', 'aider --yes'], $a->argv('P', '/app', 'v'));
        self::assertNull($a->binary());
    }

    public function testRetriesPointAtTheFeedback(): void
    {
        $first = Agent::prompt('F_x', 'F::x', '.fast-php/ports/F_x', 'VERIFY', 1, null);
        self::assertStringContainsString('.fast-php/ports/F_x/TASK.md', $first);
        self::assertStringContainsString('`VERIFY`', $first);
        self::assertStringNotContainsString('attempt', $first);
        self::assertStringContainsString('This is attempt 2. The previous port failed; .fast-php/ports/F_x/FEEDBACK.md', Agent::prompt('F_x', 'F::x', '.fast-php/ports/F_x', 'VERIFY', 2, '.fast-php/ports/F_x/FEEDBACK.md'));
    }

    public function testGuardPutsBackWhatChanged(): void
    {
        $dir = sys_get_temp_dir() . '/fastphp-guard-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/glue.rs", 'generated');
        file_put_contents("$dir/port.rs", 'stub');
        $guard = new Guard(Guard::tree($dir, static fn (string $p) => str_ends_with($p, 'port.rs')));
        file_put_contents("$dir/glue.rs", 'edited');
        file_put_contents("$dir/port.rs", 'the port');
        self::assertSame(["$dir/glue.rs"], $guard->restore());
        self::assertSame('generated', file_get_contents("$dir/glue.rs"));
        self::assertSame('the port', file_get_contents("$dir/port.rs"));
        self::assertSame([], $guard->restore());
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
}
