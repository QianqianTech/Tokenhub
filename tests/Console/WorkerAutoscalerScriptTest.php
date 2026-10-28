<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
$scriptPath = $root . '/scripts/worker-autoscaler.sh';
$composePath = $root . '/docker-compose.yml';

assert_true(is_file($scriptPath), 'Worker autoscaler script should exist.');

$script = file_get_contents($scriptPath);
assert_true(is_string($script), 'Worker autoscaler script should be readable.');
assert_true(str_contains($script, 'WORKER_AUTOSCALE_MIN'), 'Autoscaler should expose a minimum worker setting.');
assert_true(str_contains($script, 'WORKER_AUTOSCALE_MAX'), 'Autoscaler should expose a maximum worker setting.');
assert_true(str_contains($script, 'WORKER_AUTOSCALE_TASKS_PER_WORKER'), 'Autoscaler should expose queue-depth-per-worker tuning.');
assert_true(str_contains($script, 'WORKER_AUTOSCALE_COOLDOWN_SECONDS'), 'Autoscaler should enforce cooldown before scaling.');
assert_true(str_contains($script, 'redis-cli'), 'Autoscaler should read queue depth with redis-cli.');
assert_true(str_contains($script, '--scale'), 'Autoscaler should call docker compose with --scale.');
assert_true(str_contains($script, 'queue-worker='), 'Autoscaler should scale the queue-worker service.');
assert_true(str_contains($script, 'TOKENHUB_COMPOSE_PROJECT_NAME'), 'Autoscaler should use the active Compose project name.');
assert_true(str_contains($script, '--project-name "$TOKENHUB_COMPOSE_PROJECT_NAME"'), 'Autoscaler should pass its project name to Docker Compose.');

$compose = file_get_contents($composePath);
assert_true(is_string($compose), 'Docker Compose file should be readable.');
assert_true(str_contains($compose, 'worker-autoscaler:'), 'Compose should define the worker-autoscaler service.');
assert_true(str_contains($compose, '/var/run/docker.sock:/var/run/docker.sock'), 'Autoscaler should have Docker socket access.');
assert_true(!str_contains($compose, 'container_name: tokenhub-queue-worker'), 'queue-worker should not set container_name when it needs replicas.');
assert_true(str_contains($compose, 'TOKENHUB_COMPOSE_PROJECT_NAME: ${COMPOSE_PROJECT_NAME:-tokenhub}'), 'Autoscaler should receive the active Compose project name.');
assert_true(str_contains($compose, 'profiles: ["autoscaling"]'), 'Autoscaler should require explicit opt-in because Docker socket scaling needs host-specific bind paths.');
