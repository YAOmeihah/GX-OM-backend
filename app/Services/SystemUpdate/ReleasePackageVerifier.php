<?php

namespace App\Services\SystemUpdate;

use UnexpectedValueException;

class ReleasePackageVerifier
{
    private const REQUIRED_ENTRIES = [
        '.env.example',
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'public/',
        'resources/',
        'routes/',
        'vendor/',
        'artisan',
        'composer.lock',
        'release.json',
    ];

    private const BLOCKED_ROOTS = [
        '.git',
        '.github',
        'node_modules',
        'tests',
        'tools',
        'docs',
    ];

    private const BLOCKED_ROOT_FILES = [
        '.gitignore',
        '.gitattributes',
        '.editorconfig',
        'auth.json',
        'composer.json',
        'package.json',
        'package-lock.json',
        'phpstan.neon',
        'phpunit.xml',
        'postcss.config.js',
        'tailwind.config.js',
        'vite.config.js',
        'API.md',
        'PERMISSIONS.md',
        'create_test_data.php',
        'generate_test_token.php',
        'test-permissions.php',
    ];

    private const ALLOWED_TAR_TYPES = ["\0", '0', '5'];

    public function assertValidTag(string $tag): void
    {
        if (preg_match('/^v\d+\.\d+\.\d+$/', $tag) !== 1) {
            throw new UnexpectedValueException('Release tag must match vX.Y.Z.');
        }
    }

    public function assertSha256(string $packagePath, string $expectedSha256): void
    {
        $expected = strtolower(trim($expectedSha256));

        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new UnexpectedValueException('Expected SHA-256 checksum is invalid.');
        }

        if (! is_file($packagePath)) {
            throw new UnexpectedValueException('Release package does not exist.');
        }

        if (! hash_equals($expected, hash_file('sha256', $packagePath))) {
            throw new UnexpectedValueException('Release package SHA-256 mismatch.');
        }
    }

    public function assertSafeArchive(string $archivePath): void
    {
        if (! is_file($archivePath)) {
            throw new UnexpectedValueException('Release archive does not exist.');
        }

        $requiredEntries = array_fill_keys(self::REQUIRED_ENTRIES, false);
        $entryCount = 0;

        foreach ($this->readTarGzEntries($archivePath) as $entry) {
            $entryCount++;
            $this->assertSafeEntryType($entry);
            $this->assertSafeEntryPath($entry['name']);
            $this->assertNotBlockedEntry($entry['name']);

            foreach (self::REQUIRED_ENTRIES as $requiredEntry) {
                if (! $requiredEntries[$requiredEntry] && $this->entryMatchesRequired($entry['name'], $requiredEntry)) {
                    $requiredEntries[$requiredEntry] = true;
                }
            }
        }

        if ($entryCount === 0) {
            throw new UnexpectedValueException('Release archive has no entries.');
        }

        foreach ($requiredEntries as $requiredEntry => $found) {
            if (! $found) {
                throw new UnexpectedValueException("Release archive is missing required entry: {$requiredEntry}");
            }
        }
    }

    /**
     * @return \Generator<int, array{name: string, type: string}>
     */
    private function readTarGzEntries(string $archivePath): iterable
    {
        $handle = @gzopen($archivePath, 'rb');

        if ($handle === false) {
            throw new UnexpectedValueException('Release archive is not a valid gzip file.');
        }

        try {
            while (true) {
                $header = $this->readGzipBytes($handle, 512);

                if ($header === null) {
                    break;
                }

                if (strlen($header) !== 512) {
                    throw new UnexpectedValueException('Release archive has a truncated tar header.');
                }

                if ($header === str_repeat("\0", 512)) {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $type = substr($header, 156, 1);
                $size = $this->parseTarSize($header);

                if ($prefix !== '') {
                    $name = "{$prefix}/{$name}";
                }

                if ($name !== '') {
                    yield [
                        'name' => str_replace('\\', '/', $name),
                        'type' => $type,
                    ];
                }

                $this->skipGzipBytes($handle, (int) (ceil($size / 512) * 512));
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function readGzipBytes($handle, int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! gzeof($handle)) {
            $chunk = @gzread($handle, $length - strlen($buffer));

            if ($chunk === false) {
                throw new UnexpectedValueException('Release archive is not a valid gzip file.');
            }

            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        if ($buffer === '' && gzeof($handle)) {
            return null;
        }

        return $buffer;
    }

    private function parseTarSize(string $header): int
    {
        $rawSize = trim(rtrim(substr($header, 124, 12), "\0 "));

        if ($rawSize === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $rawSize) !== 1) {
            throw new UnexpectedValueException('Release archive contains an invalid tar size header.');
        }

        return octdec($rawSize);
    }

    /**
     * @param  resource  $handle
     */
    private function skipGzipBytes($handle, int $length): void
    {
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = @gzread($handle, min($remaining, 8192));

            if ($chunk === false) {
                throw new UnexpectedValueException('Release archive is not a valid gzip file.');
            }

            if ($chunk === '') {
                throw new UnexpectedValueException('Release archive ended before a tar entry was complete.');
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param  array{name: string, type: string}  $entry
     */
    private function assertSafeEntryType(array $entry): void
    {
        if (! in_array($entry['type'], self::ALLOWED_TAR_TYPES, true)) {
            throw new UnexpectedValueException("Release archive contains unsafe entry type for path: {$entry['name']}");
        }
    }

    private function assertSafeEntryPath(string $entry): void
    {
        if (
            str_starts_with($entry, '/')
            || preg_match('/^[A-Za-z]:\//', $entry) === 1
            || $entry === '..'
            || str_starts_with($entry, '../')
            || str_contains($entry, '/../')
            || str_ends_with($entry, '/..')
        ) {
            throw new UnexpectedValueException("Release archive contains unsafe path: {$entry}");
        }
    }

    private function assertNotBlockedEntry(string $entry): void
    {
        $trimmed = trim($entry, '/');
        $root = explode('/', $trimmed)[0] ?? '';

        if ($trimmed === '.env' || (str_starts_with($trimmed, '.env.') && $trimmed !== '.env.example')) {
            throw new UnexpectedValueException("Release archive contains protected environment file: {$entry}");
        }

        if (in_array($root, self::BLOCKED_ROOTS, true) || in_array($trimmed, self::BLOCKED_ROOT_FILES, true)) {
            throw new UnexpectedValueException("Release archive contains blocked development entry: {$entry}");
        }
    }

    private function entryMatchesRequired(string $entryName, string $requiredEntry): bool
    {
        $isDirectory = str_ends_with($requiredEntry, '/');
        $requiredFile = rtrim($requiredEntry, '/');

        if ($isDirectory) {
            return $entryName === $requiredFile || str_starts_with($entryName, $requiredEntry);
        }

        return $entryName === $requiredFile;
    }
}
