<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\DepartmentService;

$service = new DepartmentService();

$rootMove = $service->movedPath('/1/5/', 5, null);
assert_same('/5/', $rootMove['path'], 'Moving a department to root should reset path to its own id.');
assert_same(0, $rootMove['level'], 'Moving a department to root should set level 0.');

$childMove = $service->movedPath('/1/2/', 9, ['path' => '/1/7/', 'level' => 1]);
assert_same('/1/7/9/', $childMove['path'], 'Moving a department under a parent should append its id to parent path.');
assert_same(2, $childMove['level'], 'Moving a department under a parent should set parent level plus one.');

$descendant = $service->descendantPath('/1/2/9/11/', '/1/2/9/', '/1/7/9/', 2, 3);
assert_same('/1/7/9/11/', $descendant['path'], 'Descendant path should preserve suffix under new parent path.');
assert_same(4, $descendant['level'], 'Descendant level should shift by moved level delta.');

