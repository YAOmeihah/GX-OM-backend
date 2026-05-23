# Backend Runtime Release Package Design

## Goal

Build a clean backend runtime package for file-based server deployments. The package must contain only files needed to run the Laravel backend after dependencies and frontend assets are built.

## Context

The first `v1.0.0` backend release package was built from a repository copy with exclusion rules. That allowed development-only files to enter the archive, including documentation, test scripts, frontend build configuration, PHPUnit configuration, and local tool folders. This makes the package noisy and increases the risk of exposing files that are not needed on a production server.

## Package Policy

Use an allowlist instead of a broad copy plus exclusions.

Include these root entries:

- `.env.example`
- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `public/`
- `public/storage/`
- `resources/`
- `routes/`
- `vendor/`
- `artisan`
- `composer.lock`
- `release.json`
- clean `storage/` directory skeleton only
- `public/storage/` directory skeleton only

Do not include these root entries:

- `.scribe/`
- `.git/`, `.github/`, `.gitignore`, `.gitattributes`
- `.env`, `.env.*` except `.env.example`
- `auth.json`
- `docs/`
- `tools/`
- `tests/`
- `node_modules/`
- `storage/`
- `public/storage/`
- `.editorconfig`
- `composer.json`
- `package.json`, `package-lock.json`
- `phpstan.neon`
- `phpunit.xml`
- `postcss.config.js`
- `tailwind.config.js`
- `vite.config.js`
- root documentation files such as `API_DOCUMENTATION*` and `PERMISSION_SYSTEM*`
- root test or token helper scripts such as `create_test_data.php`, `generate_test_token.php`, and `test-permissions.php`

Keep `composer.lock` for dependency traceability. Do not keep `composer.json` because the release package already includes production `vendor/` and should not ask server operators to run dependency installation. Include `.env.example` so first-time deployments can copy it to `.env`, but never include real environment files. Include only an empty `storage` skeleton with placeholder files so Laravel has writable runtime directories after extraction without shipping local logs, cached views, test disks, or maintenance export data.

## Workflow Changes

The `Prepare release directory` step in `.github/workflows/backend-release.yml` should create `build/release` from the allowlist. It should create required parent directories before copying `release.json` into the release directory.

The package boundary verification step should assert:

- required runtime entries exist
- development-only entries listed above are absent
- `vendor/` exists
- `public/build/` exists
- checksum verification still runs from `build/assets`

## Local Validation

Add a focused local test script for the release package policy. The test should build a fixture release directory and archive, inspect archive file names, and fail if:

- any blocked development-only root entry is present
- any required runtime root entry is missing
- `composer.lock` is absent
- `composer.json` is present

The existing manifest generator tests remain unchanged.

## Release Handling

After the packaging policy is fixed, publish the next backend release tag rather than mutating `v1.0.0`. Use `v1.0.1` unless a newer tag already exists.
