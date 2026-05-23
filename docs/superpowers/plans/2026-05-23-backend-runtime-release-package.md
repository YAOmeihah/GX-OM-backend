# Backend Runtime Release Package Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change backend Release archives from repository-style packages to clean runtime packages.

**Architecture:** Keep the existing GitHub Release workflow and PHP archive builder. Replace the broad `rsync ./` copy with an explicit allowlist copy, then strengthen verification to check required runtime entries and blocked development entries. Add a local PHP test script that exercises the archive boundary policy without relying on GitHub Actions.

**Tech Stack:** GitHub Actions, Bash, PHP 8.2, Laravel release layout, PharData archives.

---

## File Structure

- Modify `.github/workflows/backend-release.yml`: change `Prepare release directory` to call the shared runtime package preparer; expand package boundary checks.
- Create `tools/prepare-backend-release.php`: copy only runtime allowlist entries into a release directory.
- Create `tools/test-backend-release-package-policy.php`: local fixture test for release archive contents.
- Update `docs/superpowers/plans/2026-05-23-backend-runtime-release-package.md`: track execution status as steps complete.
- Update Serena memory after release verification.

## Task 1: Add Release Package Policy Test

**Files:**
- Create: `tools/test-backend-release-package-policy.php`

- [x] **Step 1: Write the failing test**

Create `tools/test-backend-release-package-policy.php`:

```php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$builder = $root . '/tools/build-backend-release.php';
$tmp = sys_get_temp_dir() . '/gx-om-backend-release-package-policy-' . bin2hex(random_bytes(4));
$source = $tmp . '/release';
$assets = $tmp . '/assets';
$archive = $assets . '/fixture.tar.gz';

mkdir($source, 0777, true);
mkdir($assets, 0777, true);

$requiredEntries = [
    'app/',
    'bootstrap/',
    'config/',
    'database/',
    'public/',
    'public/build/',
    'resources/',
    'routes/',
    'vendor/',
    'artisan',
    'composer.lock',
    'release.json',
];

$blockedEntries = [
    '.scribe/',
    '.editorconfig',
    '.gitattributes',
    '.gitignore',
    '.github/',
    'API_DOCUMENTATION.md',
    'PERMISSION_SYSTEM.md',
    'composer.json',
    'create_test_data.php',
    'generate_test_token.php',
    'node_modules/',
    'package-lock.json',
    'package.json',
    'phpstan.neon',
    'phpunit.xml',
    'postcss.config.js',
    'storage/',
    'tailwind.config.js',
    'test-permissions.php',
    'tests/',
    'tools/',
    'vite.config.js',
];

try {
    foreach ($requiredEntries as $entry) {
        createFixtureEntry($source, $entry);
    }

    assertCommandSucceeds(
        'php ' . escapeshellarg($builder)
        . ' --source=' . escapeshellarg($source)
        . ' --output=' . escapeshellarg($archive)
    );

    $files = archiveFiles($archive);

    foreach ($requiredEntries as $entry) {
        assertArchiveContains($files, $entry);
    }

    foreach ($blockedEntries as $entry) {
        assertArchiveDoesNotContain($files, $entry);
    }

    fwrite(STDOUT, "Backend release package policy tests passed\n");
} finally {
    removeDirectory($tmp);
}

function createFixtureEntry(string $base, string $entry): void
{
    $path = $base . '/' . rtrim($entry, '/');
    if (str_ends_with($entry, '/')) {
        mkdir($path, 0777, true);
        file_put_contents($path . '/.keep', 'fixture');
        return;
    }

    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($path, 'fixture');
}

function archiveFiles(string $archive): array
{
    exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Failed to list archive:\n" . implode("\n", $output) . "\n");
        exit(1);
    }

    return array_values(array_filter(array_map(static fn (string $line): string => ltrim($line, './'), $output)));
}

function assertArchiveContains(array $files, string $entry): void
{
    $entry = rtrim($entry, '/');
    foreach ($files as $file) {
        if ($file === $entry || str_starts_with($file, $entry . '/')) {
            return;
        }
    }

    fwrite(STDERR, "Archive is missing required entry: {$entry}\n");
    exit(1);
}

function assertArchiveDoesNotContain(array $files, string $entry): void
{
    $entry = rtrim($entry, '/');
    foreach ($files as $file) {
        if ($file === $entry || str_starts_with($file, $entry . '/')) {
            fwrite(STDERR, "Archive contains blocked entry: {$entry}\n");
            exit(1);
        }
    }
}

function assertCommandSucceeds(string $command): void
{
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Expected success, got {$code}:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}
```

- [x] **Step 2: Run test to verify it passes against the archive builder fixture**

Run: `php tools/test-backend-release-package-policy.php`

Expected: `Backend release package policy tests passed`

- [x] **Step 3: Run syntax check**

Run: `php -l tools/test-backend-release-package-policy.php`

Expected: `No syntax errors detected in tools/test-backend-release-package-policy.php`

- [x] **Step 4: Commit**

```bash
git add tools/test-backend-release-package-policy.php docs/superpowers/plans/2026-05-23-backend-runtime-release-package.md
git commit -m "test: add backend release package policy check"
```

## Task 2: Switch Workflow To Runtime Allowlist

**Files:**
- Modify: `.github/workflows/backend-release.yml`
- Create: `tools/prepare-backend-release.php`

- [x] **Step 0: Add shared runtime package preparer**

Created `tools/prepare-backend-release.php`. It accepts `--source` and `--output`, removes any existing output path, creates the release directory, and copies only:

```text
app
bootstrap
config
database
public
resources
routes
vendor
artisan
composer.lock
```

The release manifest remains generated later by `tools/generate-backend-release-manifest.php`.

- [x] **Step 1: Update `Prepare release directory`**

Replace the broad `rsync -a ./ build/release/ ...` block with:

```bash
          runtime_entries=(
            app
            bootstrap
            config
            database
            public
            resources
            routes
            vendor
            artisan
            composer.lock
          )

          for entry in "${runtime_entries[@]}"; do
            if [[ ! -e "$entry" ]]; then
              echo "Missing runtime entry: $entry" >&2
              exit 1
            fi

            rsync -a "$entry" build/release/
          done
```

Keep the existing `php tools/generate-backend-release-manifest.php ... --output=build/release/release.json` command after the allowlist copy.

- [x] **Step 2: Expand package boundary verification**

Replace the package boundary checks with:

```bash
          tar -tzf build/assets/${{ steps.meta.outputs.package }} > build/assets/package-files.txt

          required_entries=(
            app/
            bootstrap/
            config/
            database/
            public/
            public/build/
            resources/
            routes/
            vendor/
            artisan
            composer.lock
            release.json
          )

          blocked_entries=(
            .scribe/
            .editorconfig
            .gitattributes
            .github/
            .gitignore
            API_DOCUMENTATION
            PERMISSION_SYSTEM
            composer.json
            create_test_data.php
            generate_test_token.php
            node_modules/
            package-lock.json
            package.json
            phpstan.neon
            phpunit.xml
            postcss.config.js
            storage/
            tailwind.config.js
            test-permissions.php
            tests/
            tools/
            vite.config.js
          )

          for entry in "${required_entries[@]}"; do
            grep -q "^${entry}" build/assets/package-files.txt
          done

          for entry in "${blocked_entries[@]}"; do
            ! grep -q "^${entry}" build/assets/package-files.txt
          done

          (
            cd build/assets
            sha256sum --check ${{ steps.meta.outputs.package }}.sha256
          )
```

- [x] **Step 3: Run local verification**

Run:

```bash
php -l tools/build-backend-release.php
php -l tools/generate-backend-release-manifest.php
php -l tools/test-backend-release-manifest.php
php -l tools/test-backend-release-package-policy.php
php tools/test-backend-release-manifest.php
php tools/test-backend-release-package-policy.php
composer validate --strict
```

Expected: all commands exit 0.

- [x] **Step 4: Commit**

```bash
git add .github/workflows/backend-release.yml docs/superpowers/plans/2026-05-23-backend-runtime-release-package.md
git commit -m "ci: build backend runtime release package"
```

## Task 3: Publish Clean Runtime Release

**Files:**
- No source edits expected.
- Update memory only after verification.

- [ ] **Step 1: Push main**

Run: `git push origin main`

Expected: `main -> main`

- [ ] **Step 2: Confirm next tag**

Run: `git tag --list --sort=-v:refname`

Expected: `v1.0.0` exists and `v1.0.1` does not exist.

- [ ] **Step 3: Create and push `v1.0.1`**

Run:

```bash
git tag -a v1.0.1 -m "GX-OM Backend v1.0.1"
git push origin v1.0.1
```

Expected: new tag pushed.

- [ ] **Step 4: Watch Release workflow**

Run:

```bash
gh run list --workflow "Backend Release" --limit 5
gh run watch <new-run-id> --exit-status
```

Expected: workflow completes successfully.

- [ ] **Step 5: Verify GitHub Release assets**

Run:

```bash
gh release view v1.0.1 --json tagName,name,url,isDraft,isPrerelease,assets,publishedAt
```

Expected: release is not draft, not prerelease, and contains:

- `gx-om-backend-v1.0.1.tar.gz`
- `gx-om-backend-v1.0.1.tar.gz.sha256`
- `release-manifest.json`

- [ ] **Step 6: Update Serena memory**

Update `backend/release_build_pipeline` with the runtime allowlist policy and `v1.0.1` release result.
