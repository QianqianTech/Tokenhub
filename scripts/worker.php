<?php

declare(strict_types=1);

// Kept for older systemd units. The single queue worker entry point is bin/queue-worker.php
// (reliable queue with acknowledgements, retries and heartbeat).
require dirname(__DIR__) . '/bin/queue-worker.php';
