<?php

declare(strict_types=1);

namespace Sphpd\Domain\Deployment;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    /** @var int Seconds before a command is considered timed out */
    private int $timeout;

    public function __construct(int $timeout = 300)
    {
        $this->timeout = $timeout;
    }

    /**
     * Run a shell command and return a result array.
     *
     * @return array{stdout: string, stderr: string, exitCode: int, timedOut: bool}
     */
    public function run(string $command, ?string $cwd = null): array
    {
        $process = Process::fromShellCommandline($command, $cwd);
        $process->setTimeout($this->timeout);

        $stdout = '';
        $stderr = '';
        $timedOut = false;

        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr) {
                if ($type === Process::OUT) {
                    $stdout .= $buffer;
                } else {
                    $stderr .= $buffer;
                }
            });
        } catch (ProcessTimedOutException $e) {
            $timedOut = true;
        }

        return [
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'exitCode' => $timedOut ? 124 : $process->getExitCode(),
            'timedOut' => $timedOut,
        ];
    }
}
