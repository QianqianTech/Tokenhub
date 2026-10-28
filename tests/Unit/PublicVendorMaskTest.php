<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use TokenHub\Services\Gateway\PublicVendorMask;

$mask = new PublicVendorMask();

// Non-streaming body: the vendor field is rewritten, everything else kept.
$body = ['id' => 'gen-1', 'model' => 'anthropic/claude-sonnet-4', 'provider' => 'Anthropic', 'choices' => [['message' => ['content' => 'hi']]]];
$masked = $mask->response($body);
assert_same('TokenHub', $masked['provider'], 'Upstream vendor is replaced by TokenHub.');
assert_same('anthropic/claude-sonnet-4', $masked['model'], 'Model field is left untouched.');
assert_same($body['choices'], $masked['choices'], 'Content is left untouched.');
assert_same(['id' => 'x'], $mask->response(['id' => 'x']), 'Bodies without a provider field are not changed.');

// Streaming: same result for every chunk boundary, CRLF kept, non-vendor lines byte-identical.
$stream = "data: {\"id\":\"gen-1\",\"model\":\"m\",\"provider\":\"Fireworks\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"你好\"}}]}\r\n\r\n"
    . ": keep-alive comment\n\n"
    . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"the \\\"provider\\\" word\"}}]}\n\n"
    . "data: {\"provider\":\"Fireworks\",\"choices\":[],\"usage\":{\"prompt_tokens\":12}}\n\n"
    . "data: [DONE]\n\n";
$run = static function (string $stream, int $size) use ($mask): string {
    $state = [];
    $out = '';
    foreach (str_split($stream, max(1, $size)) as $chunk) {
        $out .= $mask->streamChunk($chunk, $state);
    }

    return $out . $mask->flush($state);
};
$reference = $run($stream, strlen($stream));
assert_true(!str_contains($reference, 'Fireworks'), 'No upstream vendor reaches the client stream.');
assert_same(2, substr_count($reference, '"provider":"TokenHub"'), 'Every vendor field is rewritten.');
assert_true(str_contains($reference, "\"provider\":\"TokenHub\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"你好\"}}]}\r\n"), 'CRLF and unicode survive.');
assert_true(str_contains($reference, "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"the \\\"provider\\\" word\"}}]}\n"), 'Content that merely mentions provider is not changed.');
assert_true(str_contains($reference, ": keep-alive comment\n\ndata: {\"choices\""), 'Comment lines pass through.');
assert_true(str_ends_with($reference, "data: [DONE]\n\n"), 'Terminal marker passes through.');
foreach ([1, 2, 3, 7, 13, 64] as $size) {
    assert_same($reference, $run($stream, $size), "Byte split at {$size} yields the same output.");
}
assert_same("data: {\"provider\":\"TokenHub\"}", $run("data: {\"provider\":\"X\"}", 5), 'A final line without newline is flushed and masked.');

echo "PublicVendorMaskTest OK\n";
