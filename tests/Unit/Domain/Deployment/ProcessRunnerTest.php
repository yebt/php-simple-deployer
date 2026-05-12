<?php

declare(strict_types=1);

use Sphpd\Domain\Deployment\ProcessRunner;

test('ProcessRunner: successful command returns exit code 0', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('exit 0');

    expect($result['exitCode'])->toBe(0);
    expect($result['timedOut'])->toBeFalse();
});

test('ProcessRunner: failed command returns correct non-zero exit code', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('exit 42');

    expect($result['exitCode'])->toBe(42);
    expect($result['timedOut'])->toBeFalse();
});

test('ProcessRunner: stdout is captured', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('echo "hello world"');

    expect($result['stdout'])->toContain('hello world');
    expect($result['exitCode'])->toBe(0);
});

test('ProcessRunner: stderr is captured separately from stdout', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('echo "out" && echo "err" >&2');

    expect($result['stdout'])->toContain('out');
    expect($result['stderr'])->toContain('err');
});

test('ProcessRunner: stdout and stderr are independent streams', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('echo "only_stdout"');

    expect($result['stdout'])->toContain('only_stdout');
    expect($result['stderr'])->toBe('');
});

test('ProcessRunner: command with timeout returns exit code 124', function () {
    $runner = new ProcessRunner(1);
    $result = $runner->run('sleep 10');

    expect($result['exitCode'])->toBe(124);
    expect($result['timedOut'])->toBeTrue();
});

test('ProcessRunner: cwd is respected', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('pwd', '/tmp');

    expect(trim($result['stdout']))->toBe('/tmp');
});

test('ProcessRunner: multiline output is captured fully', function () {
    $runner = new ProcessRunner();
    $result = $runner->run('printf "line1\nline2\nline3\n"');

    expect($result['stdout'])->toContain('line1');
    expect($result['stdout'])->toContain('line2');
    expect($result['stdout'])->toContain('line3');
});

test('ProcessRunner: timedOut is false for commands that complete in time', function () {
    $runner = new ProcessRunner(5);
    $result = $runner->run('echo "fast"');

    expect($result['timedOut'])->toBeFalse();
});

test('ProcessRunner: large output arriving in multiple chunks is captured fully', function () {
    // Generate output large enough to guarantee multiple pipe buffer flushes.
    // 'yes' pipes ~64 KB chunks; limiting with head -n gives us a deterministic
    // count while still exercising multi-callback accumulation in ProcessRunner.
    $runner = new ProcessRunner();
    $result = $runner->run('yes "x" | head -n 10000');

    $lines = explode("\n", trim($result['stdout']));
    expect(count($lines))->toBe(10000);
    expect($result['exitCode'])->toBe(0);
});

test('ProcessRunner: exit code is correct even after large output', function () {
    $runner = new ProcessRunner();
    // Large output followed by a deliberate failure.
    $result = $runner->run('yes "x" | head -n 5000; exit 7');

    expect($result['exitCode'])->toBe(7);
    expect($result['stdout'])->toContain('x');
});
