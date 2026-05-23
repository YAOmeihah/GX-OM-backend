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

        $entries = $this->readTarGzEntries($archivePath);

        foreach ($entries as $entry) {
            $this->assertSafeEntryType($entry);
            $this->assertSafeEntryPath($entry['name']);
            $this->assertNotBlockedEntry($entry['name']);
        }

        foreach (self::REQUIRED_ENTRIES as $requiredEntry) {
            if (! $this->hasEntry($entries, $requiredEntry)) {
                throw new UnexpectedValueException("Release archive is missing required entry: {$requiredEntry}");
            }
        }
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function readTarGzEntries(string $archivePath): array
    {
        $contents = gzdecode((string) file_get_contents($archivePath));

        if ($contents === false) {
            throw new UnexpectedValueException('Release archive is not a valid gzip file.');
        }

        $entries = [];
        $offset = 0;
        $length = strlen($contents);

        while ($offset + 512 <= $length) {
            $header = substr($contents, $offset, 512);
            $offset += 512;

            if ($header === str_repeat("\0", 512)) {
                break;
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $type = substr($header, 156, 1);
            $size = octdec(trim(rtrim(substr($header, 124, 12), "\0 ")) ?: '0');

            if ($prefix !== '') {
                $name = "{$prefix}/{$name}";
            }

            if ($name !== '') {
                $entries[] = [
                    'name' => str_replace('\\', '/', $name),
                    'type' => $type,
                ];
            }

            $offset += (int) (ceil($size / 512) * 512);
        }

        if ($entries === []) {
            throw new UnexpectedValueException('Release archive has no entries.');
        }

        return $entries;
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

    /**
     * @param  list<array{name: string, type: string}>  $entries
     */
    private function hasEntry(array $entries, string $requiredEntry): bool
    {
        $isDirectory = str_ends_with($requiredEntry, '/');
        $requiredFile = rtrim($requiredEntry, '/');

        foreach ($entries as $entry) {
            $entryName = $entry['name'];

            if ($isDirectory) {
                if ($entryName === $requiredFile || str_starts_with($entryName, $requiredEntry)) {
                    return true;
                }

                continue;
            }

            if ($entryName === $requiredFile) {
                return true;
            }
        }

        return false;
    }
}
