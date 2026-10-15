<?php
if (PHP_SAPI !== 'cli') { exit(1); }
preg_match('/^ \* Version: (\S+)/m', file_get_contents(dirname(__DIR__) . '/clerk-wp-sync.php'), $version);
$path = $argv[1] ?? dirname(__DIR__) . '/release/clerk-wp-sync-' . $version[1] . '.zip';
$zip = new ZipArchive();
if ($zip->open($path) !== true) { throw new RuntimeException('Cannot open candidate ZIP.'); }
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    check(str_starts_with($name, 'clerk-wp-sync/') && !in_array('..', explode('/', $name), true), 'safe plugin root');
    check(!preg_match('~^clerk-wp-sync/(?:deploy|tests|docs|bin|\.git|\.env|release)(?:/|$)~', $name), 'development/private files excluded');
}
foreach (['clerk-wp-sync.php', 'includes/Auth/frontend-proxy.php', 'includes/Auth/SessionBridge.php', 'includes/Api/Client.php', 'assets/js/clerk-auth.js'] as $path) {
    check($zip->getFromName('clerk-wp-sync/' . $path) === file_get_contents(dirname(__DIR__) . '/' . $path), 'package matches source ' . $path);
}
check($zip->locateName('clerk-wp-sync/vendor/autoload.php') !== false, 'Composer runtime included');
$zip->close();
echo "$checks package checks passed.\n";
