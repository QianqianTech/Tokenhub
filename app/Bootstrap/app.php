<?php

declare(strict_types=1);

use TokenHub\Bootstrap\Kernel;
use TokenHub\Support\App;

App::bootstrap(dirname(__DIR__, 2));

return [Kernel::router(), []];
