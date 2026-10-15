#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI-only build tool.');
}
chdir(dirname(__DIR__));
if (!in_array('--skip-composer', $argv, true)) {
    passthru('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader', $status);
    if (0 !== $status) {
        fwrite(STDERR, "Composer install failed.\n"); exit(1);
    }
}
if (!is_file('vendor/autoload.php')) {
    fwrite(STDERR, "Runtime dependencies are missing.\n"); exit(1);
}
preg_match('/^ \* Version: (\S+)/m', file_get_contents('clerk-wp-sync.php'), $match);
if (!isset($match[1]) || !preg_match('/^[A-Za-z0-9.\-]+$/D', $match[1])) {
    throw new RuntimeException('Invalid package version.');
}
if (!is_dir('release')) { mkdir('release', 0755); }
$output = 'release/clerk-wp-sync-' . $match[1] . '.zip';
$temporary = $output . '.tmp';
$zip = new ZipArchive();
if (true !== $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
    throw new RuntimeException('Cannot open package.');
}
$count = 0;
$add = static function (string $path) use ($zip, &$count) {
    if (is_link($path)) { throw new RuntimeException('Package source contains a symlink.'); }
    foreach (explode('/', $path) as $part) {
        if (str_starts_with($part, '.git') || str_starts_with($part, '.env') || in_array($part, ['node_modules', '.DS_Store'], true)) { return; }
    }
    if (!$zip->addFile($path, 'clerk-wp-sync/' . $path)) {
        throw new RuntimeException('Cannot add package file.');
    }
    $count++;
};
// Local DDEV routing/guards, tests and build tools never ship to production.
foreach (['clerk-wp-sync.php', 'uninstall.php', 'readme.txt', 'includes', 'admin', 'assets', 'vendor'] as $entry) {
    if (is_link($entry)) { throw new RuntimeException('Package root contains a symlink.'); }
    if (is_file($entry)) { $add($entry); continue; }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entry, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isLink()) { throw new RuntimeException('Package source contains a symlink.'); }
        if ($file->isFile()) { $add($file->getPathname()); }
    }
}
if (!$zip->close() || !rename($temporary, $output)) {
    throw new RuntimeException('Cannot save package.');
}
echo json_encode(['package' => $output, 'files' => $count, 'sha256' => hash_file('sha256', $output)], JSON_UNESCAPED_SLASHES) . "\n";
