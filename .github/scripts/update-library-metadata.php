<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$libraryPath = $repoRoot . DIRECTORY_SEPARATOR . 'library.json';
$version = null;
$includeNextCommit = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--next-build') {
        $includeNextCommit = true;
        continue;
    }

    if (str_starts_with($argument, '--version=')) {
        $version = substr($argument, strlen('--version='));
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
    exit(2);
}

if ($version !== null && !preg_match('/^\d+(?:\.\d+){1,2}$/', $version)) {
    fwrite(STDERR, 'Invalid version: ' . $version . PHP_EOL);
    exit(2);
}

$library = json_decode(file_get_contents($libraryPath) ?: '', true, 512, JSON_THROW_ON_ERROR);
if (!is_array($library)) {
    fwrite(STDERR, 'library.json does not contain a JSON object.' . PHP_EOL);
    exit(1);
}

$build = getGitCommitCount($repoRoot) + ($includeNextCommit ? 1 : 0);

if ($version !== null) {
    $library['version'] = $version;
}

$library['build'] = $build;
$library['date'] = time();

file_put_contents(
    $libraryPath,
    json_encode($library, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
);

echo 'library.json updated: version=' . $library['version'] . ', build=' . $library['build'] . ', date=' . $library['date'] . PHP_EOL;

function getGitCommitCount(string $repoRoot): int
{
    $command = 'git -C ' . escapeshellarg($repoRoot) . ' rev-list --count HEAD';
    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !isset($output[0]) || !is_numeric($output[0])) {
        fwrite(STDERR, 'Could not determine git commit count.' . PHP_EOL);
        exit(1);
    }

    return (int) $output[0];
}
