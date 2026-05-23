# Backend GitHub Release Build Implementation Plan

> **For agentic workers:** implement this plan task-by-task. Keep release packaging separate from runtime update features.

**Goal:** Add a GitHub Actions release pipeline that builds a deployable `.tar.gz` backend package from tagged source and publishes it as a GitHub Release. This is the foundation for later manual backend updates from the admin panel.

**Architecture:** Keep CI and release concerns separate. CI continues to validate code quality. A new release workflow builds production dependencies, compiles frontend assets, assembles a clean archive, emits manifest/checksum metadata, and publishes the release artifact. The package should include `vendor/` and `public/build/`, and exclude `node_modules/`.

**Tech Stack:** Laravel 11, PHP 8.2, Composer 2.x, Node/Vite, GitHub Actions, GNU tar.

---

## File Structure

- Create: `.github/workflows/backend-release.yml`
- Modify: `.github/workflows/backend-ci.yml` only if release-related names or shared helpers need alignment; otherwise leave it untouched
- Create: `tools/build-backend-release.php` or an equivalent packaging script if the workflow needs a reusable packager
- Create: `tools/generate-backend-release-manifest.php` if a separate manifest generator is cleaner than inline YAML shell logic
- Create: `phpstan.neon` only if the release task needs static validation for new helper scripts
- Add release assets:
  - `gx-om-backend-vX.Y.Z.tar.gz`
  - `release-manifest.json`
  - `sha256` checksum file

---

## Execution Rules

- Do not modify the future admin update flow in this pass.
- Do not include `node_modules/` in the release package.
- Keep `vendor/` and `public/build/` in the release package.
- Use tag push (`v*`) as the primary release trigger and `workflow_dispatch` as a fallback.
- Publish GitHub Releases, not just workflow artifacts.
- Keep the release workflow independent from CI.
- Prefer a packaging script if shell logic becomes hard to read or reuse.

---

### Task 1: Design the Release Workflow Contract

**Files:**
- New: `.github/workflows/backend-release.yml`
- New: `tools/build-backend-release.php` or inline workflow steps
- New: `tools/generate-backend-release-manifest.php` or inline workflow steps

- [ ] Confirm the release trigger model.
  - Primary: push of a `v*` tag.
  - Secondary: manual `workflow_dispatch`.

- [ ] Define the release inputs and derived values.
  - Version comes from tag name or manual input.
  - Commit SHA and build timestamp are recorded in the manifest.
  - Package filename follows `gx-om-backend-vX.Y.Z.tar.gz`.

- [ ] Define package contents and exclusions.
  - Include application code, `vendor/`, `public/build/`, bootstrap/runtime files, and manifest metadata.
  - Exclude `node_modules/`, `.git/`, `.github/`, `.env`, `storage/`, `tests/`, and other dev-only files.

- [ ] Decide whether the package root mirrors the repository root or uses a nested release directory.
  - Recommended: package the repository root content with a clean top-level layout so deployment can extract directly into a version folder.

- [ ] Confirm the release asset set.
  - `.tar.gz`
  - `release-manifest.json`
  - `sha256` checksum file

---

### Task 2: Implement the GitHub Actions Release Workflow

**Files:**
- Create: `.github/workflows/backend-release.yml`

- [ ] Add the release workflow scaffold.
  - Include trigger definitions for tag push and manual dispatch.
  - Set minimal required permissions for creating releases and uploading assets.

- [ ] Add build environment setup.
  - PHP 8.2.
  - Compatible Node runtime.
  - Composer and npm cache if helpful.

- [ ] Add release build steps.
  - `composer validate --strict`
  - `composer install --no-dev --optimize-autoloader`
  - `npm ci`
  - `npm run build`
  - remove or ignore files that should not ship
  - create the tarball
  - generate checksum
  - generate manifest

- [ ] Add GitHub Release publication steps.
  - Create or update the release for the current tag.
  - Upload the tarball, checksum file, and manifest.
  - Include release notes from the tag body or a simple generated message.

- [ ] Keep the workflow readable.
  - Prefer explicit shell steps over dense one-liners if the archive process gets messy.
  - Extract helper scripts if the workflow begins to duplicate logic.

---

### Task 3: Define the Release Manifest Format

**Files:**
- Create: `release-manifest.json` generation logic, either in workflow or a helper script

- [ ] Add the manifest fields.
  - release name
  - semantic version
  - git tag
  - commit SHA
  - build timestamp
  - package filename
  - sha256
  - optional changelog or notes

- [ ] Ensure the manifest is stable for future admin-side version checks.
  - Use simple JSON keys.
  - Avoid embedding environment-specific paths.
  - Keep the file small and machine-readable.

- [ ] Make manifest generation deterministic enough for repeatable builds.
  - Same source tag should produce the same version metadata.
  - Timestamp may differ, but core identity fields should not.

---

### Task 4: Verify the Release Package Boundary

**Files:**
- The workflow or helper scripts from Tasks 1-3

- [ ] Check the package does not contain `node_modules/`.
- [ ] Check the package does contain `vendor/`.
- [ ] Check the package does contain `public/build/`.
- [ ] Check the package does not contain `.env`, `.git/`, `.github/`, `storage/`, or `tests/`.
- [ ] Verify the tarball can be extracted cleanly into a fresh directory.
- [ ] Verify the checksum matches the archive contents.

---

### Task 5: Validate Workflow Syntax and Release Behavior

**Files:**
- `.github/workflows/backend-release.yml`

- [ ] Validate the workflow file structure.
- [ ] Confirm the release workflow does not interfere with CI.
- [ ] Confirm tag naming maps cleanly to version naming.
- [ ] Confirm manual dispatch can supply an explicit version when needed.

Suggested verification commands:

```powershell
php -l tools/build-backend-release.php
php -l tools/generate-backend-release-manifest.php
composer validate --strict
```

If helper scripts are not created, replace the `php -l` checks with direct workflow linting or a local dry-run of the packaging commands.

---

### Task 6: Final Verification

Run a local dry run of the packaging logic if possible, then verify:

- the release workflow file is valid YAML
- the package name is correct
- the release manifest contains the expected fields
- the archive excludes `node_modules/`
- the archive includes `vendor/` and `public/build/`
- the workflow can publish a GitHub Release from a tagged build

---

### Commit Strategy

- Commit the workflow and helper scripts together once the packaging boundary is correct.
- Keep any later admin update implementation in a separate plan and commit sequence.
