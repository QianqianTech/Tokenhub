<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

register_rag_test_cleanup();

use TokenHub\Support\App;

$db = App::db();
$suffix = bin2hex(random_bytes(4));
$kbIds = ['kb_del_' . $suffix, 'kb_route_' . $suffix, 'kb_e2e_' . $suffix];

try {
    foreach ($kbIds as $kbId) {
        $db->execute(
            'INSERT INTO rag_knowledge_bases (kb_id, name, status) VALUES (?, ?, "active")',
            [$kbId, 'Cleanup KB ' . $kbId]
        );
    }

    cleanup_synthetic_rag_test_data();

    foreach ($kbIds as $kbId) {
        $row = $db->first('SELECT id FROM rag_knowledge_bases WHERE kb_id = ?', [$kbId]);
        assert_same(null, $row, 'RAG test cleanup should remove synthetic knowledge base ' . $kbId . '.');
    }
} finally {
    foreach ($kbIds as $kbId) {
        $db->execute('DELETE FROM rag_knowledge_bases WHERE kb_id = ?', [$kbId]);
    }
}
