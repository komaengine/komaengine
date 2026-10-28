<?php

// Fails a release build when Composer installed any package from source
// instead of its published dist archive: a source install is a Git checkout
// whose bytes are not the ones the lock file's dist checksum describes.
//
//   php scripts/release/assert-dist-vendor.php VENDOR_DIRECTORY

$vendor = $argv[1] ?? '';
$installed = $vendor.'/composer/installed.json';

if ($vendor === '' || ! is_file($installed)) {
    fwrite(STDERR, "Usage: php assert-dist-vendor.php VENDOR_DIRECTORY (with composer/installed.json)\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($installed), true);
$packages = is_array($data) ? ($data['packages'] ?? $data) : null;

if (! is_array($packages)) {
    fwrite(STDERR, "Cannot read the installed package list: {$installed}\n");
    exit(1);
}

$problems = [];

foreach ($packages as $package) {
    $source = $package['installation-source'] ?? null;

    if ($source !== 'dist') {
        $problems[] = sprintf('%s was installed from %s, not dist', $package['name'] ?? '(unnamed)', $source ?? 'an unknown source');
    }
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($files as $file) {
    if ($file->getFilename() === '.git') {
        $problems[] = 'Git metadata in '.substr($file->getPathname(), strlen($vendor) + 1);
    }
}

if ($problems !== []) {
    fwrite(STDERR, "Composer dependencies must come from dist archives:\n  ".implode("\n  ", $problems)."\n");
    exit(1);
}

printf("All %d Composer packages were installed from dist archives.\n", count($packages));
