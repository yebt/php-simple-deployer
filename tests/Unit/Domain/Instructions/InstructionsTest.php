<?php

declare(strict_types=1);

use Sphpd\Domain\Instructions\Instructions;

// ── JSON parsing ─────────────────────────────────────────────────────────────

test('Instructions: loads valid JSON string', function () {
    $instructions = new Instructions();
    $json = json_encode([
        ['name' => 'Build', 'run' => 'npm run build'],
    ]);

    $result = $instructions->loadFromJson($json);

    expect($result)->toHaveKey('tasks');
    expect($result['tasks'])->toHaveCount(1);
    expect($result['tasks'][0]['name'])->toBe('Build');
});

test('Instructions: returns error for invalid JSON', function () {
    $instructions = new Instructions();

    $result = $instructions->loadFromJson('{not valid json');

    expect($result)->toHaveKey('error');
});

// ── YAML parsing ─────────────────────────────────────────────────────────────

test('Instructions: loads valid YAML string', function () {
    $instructions = new Instructions();
    $yaml = "- name: Deploy\n  run: make deploy\n";

    $result = $instructions->loadFromYaml($yaml);

    expect($result)->toHaveKey('tasks');
    expect($result['tasks'][0]['name'])->toBe('Deploy');
});

test('Instructions: returns error for invalid YAML', function () {
    $instructions = new Instructions();

    $result = $instructions->loadFromYaml("key: [unclosed");

    expect($result)->toHaveKey('error');
});

// ── Task structure validation ─────────────────────────────────────────────────

test('Instructions: run as array of commands is valid', function () {
    $instructions = new Instructions();
    $json = json_encode([
        ['name' => 'Multi', 'run' => ['echo one', 'echo two']],
    ]);

    $result = $instructions->loadFromJson($json);

    expect($result)->toHaveKey('tasks');
});

test('Instructions: task missing run is rejected', function () {
    $instructions = new Instructions();
    $json = json_encode([['name' => 'No run here']]);

    $result = $instructions->loadFromJson($json);

    expect($result)->toHaveKey('error');
    expect($result['error'])->toContain('run');
});

test('Instructions: task with run=integer is rejected', function () {
    $instructions = new Instructions();
    $json = json_encode([['name' => 'Bad', 'run' => 123]]);

    $result = $instructions->loadFromJson($json);

    expect($result)->toHaveKey('error');
});

test('Instructions: validate returns true for valid tasks', function () {
    $instructions = new Instructions();
    $tasks = [['name' => 'Test', 'run' => 'echo ok']];

    expect($instructions->validate($tasks))->toBeTrue();
});

test('Instructions: validate returns string error for non-array input', function () {
    $instructions = new Instructions();

    $result = $instructions->validate('not an array');

    expect($result)->toBeString();
    expect($result)->toContain('array');
});
