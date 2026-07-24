<?php

declare(strict_types=1);

$roots = [
    __DIR__ . '/../Kalender',
    __DIR__ . '/../Kalender Ansicht',
    __DIR__ . '/../Kalender Konfigurator',
    __DIR__ . '/../Kalender Konto',
    __DIR__ . '/../libs'
];

$missing = [];
foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            throw new RuntimeException('Could not read ' . $file->getPathname());
        }

        preg_match_all(
            '/\bpublic\s+(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[1] as $index => $methodMatch) {
            $method = $methodMatch[0];
            $declarationOffset = $matches[0][$index][1];
            $prefix = rtrim(substr($source, 0, $declarationOffset));
            if (!str_ends_with($prefix, '*/')) {
                $missing[] = relativePath($file->getPathname()) . '::' . $method;
                continue;
            }

            $docStart = strrpos($prefix, '/**');
            $commentStart = strrpos($prefix, '/*');
            if ($docStart === false || $commentStart !== $docStart) {
                $missing[] = relativePath($file->getPathname()) . '::' . $method;
            }
        }
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Missing PHPDoc for public methods:\n - " . implode("\n - ", $missing) . "\n");
    exit(1);
}

echo "Public PHPDoc coverage: OK\n";

function relativePath(string $path): string
{
    $root = realpath(__DIR__ . '/..');
    $realPath = realpath($path);
    if ($root === false || $realPath === false) {
        return $path;
    }

    return ltrim(str_replace('\\', '/', substr($realPath, strlen($root))), '/');
}
