<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

// Colours live in tokens.css so both themes stay complete; the other admin
// stylesheets may only reference tokens.
$cssDir = __DIR__ . '/../../public/admin/assets/css';
$tokens = (string) file_get_contents($cssDir . '/tokens.css');
$sheets = [];
foreach (['app.css', 'components.css', 'redesign.css'] as $name) {
    $sheets[$name] = (string) file_get_contents($cssDir . '/' . $name);
}

foreach ($sheets as $name => $css) {
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $css, $hex);
    assert_true($hex[0] === [], $name . ' must use colour tokens instead of hex literals: ' . implode(', ', array_unique($hex[0])));
    preg_match_all('/rgba?\(\s*\d+[ ,]+\d+[ ,]+\d+[^)]*\)/', $css, $rgb);
    $nonShadow = array_filter($rgb[0], static fn (string $value): bool => !str_contains($css, 'box-shadow: 14px 0 36px ' . $value));
    assert_true($nonShadow === [], $name . ' must use colour tokens instead of rgb() literals: ' . implode(', ', $nonShadow));
}

assert_true(!preg_match('/\.app-shell\.theme-(light|dark) \{\s*--/', $sheets['app.css']), 'Theme variables must be defined only in tokens.css.');

// Every custom property referenced without a fallback must be defined somewhere.
$all = $tokens . implode("\n", $sheets);
preg_match_all('/--([a-z0-9-]+)\s*:/', $all, $defined);
preg_match_all('/var\(--([a-z0-9-]+)\s*\)/', $all, $used);
$missing = array_values(array_diff(array_unique($used[1]), array_unique($defined[1])));
assert_true($missing === [], 'Undefined CSS tokens: ' . implode(', ', $missing));

foreach (['light', 'dark'] as $theme) {
    assert_true(str_contains($tokens, '.app-shell.theme-' . $theme), 'tokens.css should define the ' . $theme . ' theme.');
}
preg_match('/\.app-shell\.theme-dark \{(.*?)\n\}/s', $tokens, $dark);
preg_match('/:root,\n\.app-shell,\n\.app-shell\.theme-light \{(.*?)\n\}/s', $tokens, $light);
preg_match_all('/--([a-z0-9-]+):/', $light[1] ?? '', $lightVars);
preg_match_all('/--([a-z0-9-]+):/', $dark[1] ?? '', $darkVars);
assert_true($lightVars[1] !== [], 'Light theme tokens should be parsable.');
assert_true(array_values(array_diff($lightVars[1], $darkVars[1])) === [], 'Dark theme must define every light theme token: ' . implode(', ', array_diff($lightVars[1], $darkVars[1])));
