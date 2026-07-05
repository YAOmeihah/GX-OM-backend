<?php

namespace Tests\Unit;

use App\Services\SystemUpdate\ReleasePackageVerifier;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ReleasePackageVerifierTest extends TestCase
{
    public function test_verifier_rejects_tarball_traversal(): void
    {
        $archivePath = $this->fixturePath('unsafe.tar.gz');
        $this->writeGzipTar($archivePath, [
            '../evil.php' => 'bad',
        ]);

        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertSafeArchive($archivePath);
    }

    public function test_verifier_accepts_required_release_archive_entries(): void
    {
        $archivePath = $this->fixturePath('safe.tar.gz');
        $this->writeGzipTar($archivePath, $this->requiredArchiveEntries());

        $verifier = new ReleasePackageVerifier;

        $verifier->assertSafeArchive($archivePath);

        $this->addToAssertionCount(1);
    }

    public function test_verifier_scans_large_archives_without_fully_decompressing_into_memory(): void
    {
        $archivePath = $this->fixturePath('large-safe.tar.gz');
        $this->writeLargeGzipTar($archivePath, [
            ...$this->requiredArchiveEntries(),
            'public/assets/large.bin' => 80 * 1024 * 1024,
        ]);

        $verifier = new ReleasePackageVerifier;
        $before = memory_get_usage(true);

        $verifier->assertSafeArchive($archivePath);

        $memoryDelta = memory_get_peak_usage(true) - $before;

        $this->assertLessThan(
            16 * 1024 * 1024,
            $memoryDelta,
            'Release archive verification should stream tar entries instead of loading the full decompressed archive.'
        );
    }

    public function test_verifier_rejects_invalid_release_tag(): void
    {
        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertValidTag('1.2.4');
    }

    public function test_verifier_rejects_sha256_mismatch(): void
    {
        $packagePath = $this->fixturePath('package.tar.gz');
        file_put_contents($packagePath, 'package');

        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertSha256($packagePath, str_repeat('0', 64));
    }

    public function test_verifier_rejects_blocked_root_dev_entries(): void
    {
        $blockedEntries = [
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

        foreach ($blockedEntries as $blockedEntry) {
            $archivePath = $this->fixturePath('blocked-'.str_replace(['.', '/'], '-', $blockedEntry).'.tar.gz');
            $this->writeGzipTar($archivePath, [
                ...$this->requiredArchiveEntries(),
                $blockedEntry => 'dev-only',
            ]);

            $verifier = new ReleasePackageVerifier;

            try {
                $verifier->assertSafeArchive($archivePath);
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->fail("Expected blocked entry [{$blockedEntry}] to be rejected.");
        }
    }

    public function test_verifier_rejects_unsafe_tar_entry_types(): void
    {
        $unsafeTypes = [
            'hardlink' => ['type' => '1', 'linkname' => 'artisan'],
            'symlink' => ['type' => '2', 'linkname' => '../storage/app/public'],
            'character-device' => ['type' => '3', 'linkname' => ''],
        ];

        foreach ($unsafeTypes as $label => $metadata) {
            $archivePath = $this->fixturePath("{$label}.tar.gz");
            $this->writeGzipTar($archivePath, $this->requiredArchiveEntries(), [
                ['name' => "public/{$label}", 'contents' => '', ...$metadata],
            ]);

            $verifier = new ReleasePackageVerifier;

            try {
                $verifier->assertSafeArchive($archivePath);
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);

                continue;
            }

            $this->fail("Expected unsafe tar entry type [{$label}] to be rejected.");
        }
    }

    public function test_verifier_requires_exact_match_for_artisan_file(): void
    {
        $archivePath = $this->fixturePath('artisan-prefix.tar.gz');
        $entries = $this->requiredArchiveEntries();
        unset($entries['artisan']);
        $entries['artisan.php'] = '#!/usr/bin/env php';
        $this->writeGzipTar($archivePath, $entries);

        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertSafeArchive($archivePath);
    }

    public function test_verifier_requires_exact_match_for_composer_lock_file(): void
    {
        $archivePath = $this->fixturePath('composer-lock-prefix.tar.gz');
        $entries = $this->requiredArchiveEntries();
        unset($entries['composer.lock']);
        $entries['composer.lock.bak'] = '{}';
        $this->writeGzipTar($archivePath, $entries);

        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertSafeArchive($archivePath);
    }

    public function test_verifier_requires_exact_match_for_release_json_file(): void
    {
        $archivePath = $this->fixturePath('release-json-prefix.tar.gz');
        $entries = $this->requiredArchiveEntries();
        unset($entries['release.json']);
        $entries['release.json.bak'] = '{"version":"1.2.4"}';
        $this->writeGzipTar($archivePath, $entries);

        $verifier = new ReleasePackageVerifier;

        $this->expectException(UnexpectedValueException::class);

        $verifier->assertSafeArchive($archivePath);
    }

    private function fixturePath(string $filename): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gx-om-release-verifier-tests';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * @return array<string, string>
     */
    private function requiredArchiveEntries(): array
    {
        return [
            '.env.example' => '',
            'app/Services/Example.php' => '<?php',
            'bootstrap/app.php' => '<?php',
            'config/app.php' => '<?php return [];',
            'database/migrations/example.php' => '<?php',
            'public/index.php' => '<?php',
            'resources/views/.gitkeep' => '',
            'routes/api.php' => '<?php',
            'vendor/autoload.php' => '<?php',
            'artisan' => '#!/usr/bin/env php',
            'composer.lock' => '{}',
            'release.json' => '{"version":"1.2.4"}',
        ];
    }

    /**
     * @param  array<string, string>  $entries
     * @param  list<array{name: string, contents: string, type?: string, linkname?: string}>  $specialEntries
     */
    private function writeGzipTar(string $path, array $entries, array $specialEntries = []): void
    {
        $tar = '';

        foreach ($entries as $name => $contents) {
            $tar .= $this->tarHeader($name, strlen($contents));
            $tar .= $contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512);
        }

        foreach ($specialEntries as $entry) {
            $contents = $entry['contents'];
            $tar .= $this->tarHeader(
                $entry['name'],
                strlen($contents),
                $entry['type'] ?? '0',
                $entry['linkname'] ?? ''
            );
            $tar .= $contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512);
        }

        $tar .= str_repeat("\0", 1024);

        file_put_contents($path, gzencode($tar));
    }

    /**
     * @param  array<string, int|string>  $entries
     */
    private function writeLargeGzipTar(string $path, array $entries): void
    {
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new \RuntimeException('Unable to create gzip tar fixture.');
        }

        try {
            foreach ($entries as $name => $contents) {
                if (is_int($contents)) {
                    gzwrite($handle, $this->tarHeader($name, $contents));

                    $remaining = $contents;
                    $chunk = str_repeat("\0", 1024 * 1024);

                    while ($remaining > 0) {
                        $bytes = min($remaining, strlen($chunk));
                        gzwrite($handle, substr($chunk, 0, $bytes));
                        $remaining -= $bytes;
                    }

                    gzwrite($handle, str_repeat("\0", (512 - $contents % 512) % 512));

                    continue;
                }

                gzwrite($handle, $this->tarHeader($name, strlen($contents)));
                gzwrite($handle, $contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512));
            }

            gzwrite($handle, str_repeat("\0", 1024));
        } finally {
            gzclose($handle);
        }
    }

    private function tarHeader(string $name, int $size, string $type = '0', string $linkname = ''): string
    {
        $header = str_pad($name, 100, "\0");
        $header .= str_pad('0000644', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad('0000000', 8, "\0");
        $header .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0";
        $header .= str_pad('00000000000', 12, "\0");
        $header .= '        ';
        $header .= $type;
        $header .= str_pad($linkname, 100, "\0");
        $header .= "ustar\0";
        $header .= '00';
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 32);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 8);
        $header .= str_repeat("\0", 155);
        $header .= str_repeat("\0", 12);
        $header = str_pad($header, 512, "\0");

        $checksum = 0;
        for ($index = 0; $index < 512; $index++) {
            $checksum += ord($header[$index]);
        }

        return substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    }
}
