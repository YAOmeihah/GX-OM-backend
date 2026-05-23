# Backend GitHub Release Build Design

## Scope

This pass adds a GitHub Release build pipeline for GX-OM-backend. The goal is to produce a deployable `.tar.gz` package automatically from tagged source, so the backend can be shipped as a server-side file deployment artifact later.

This pass does not implement the admin-side check-update flow yet. It only standardizes the release artifact and the GitHub Actions release workflow so the later manual update feature can consume a stable package format.

Current constraints and agreed rules:

- The release package format is `.tar.gz`.
- `node_modules/` must not be included in the package.
- `vendor/` must be included in the package.
- `public/build/` must be included in the package.
- The existing `backend-ci.yml` remains test-only.
- Release publication should be separate from CI.
- The release workflow should support tag-driven publishing, with `workflow_dispatch` as a fallback.

## Goal

Create a repeatable release pipeline that builds a production-ready backend archive and publishes it to GitHub Releases.

The archive should be suitable for later server-side manual deployment by downloading, verifying, extracting, and switching versions.

## Approach Options

### Option A: Tag-Driven Release Workflow

Use a dedicated GitHub Actions workflow that runs when a `v*` tag is pushed. It installs PHP and Node dependencies, builds frontend assets, packages the app into a `.tar.gz`, generates a manifest and checksum, and publishes a GitHub Release.

This is the recommended approach because it is predictable, versioned, and matches the future manual deployment flow.

### Option B: Manual-Only Release Workflow

Expose only `workflow_dispatch` and create releases by hand from GitHub Actions.

This is simpler for early testing, but it is easy to drift from the future update workflow and does not give a clean versioned release path.

### Option C: CI Artifact Only

Build the package in Actions but publish only workflow artifacts, not GitHub Releases.

This is the weakest option for the project because the eventual admin update flow needs a stable release source.

## Selected Design

Use Option A, with `workflow_dispatch` as a fallback trigger for re-runs and emergency rebuilds.

The workflow should be isolated from the existing CI workflow. CI continues to validate code quality; release workflow handles packaging and publication only.

## Architecture

### Workflow Layout

Add a new workflow such as `.github/workflows/backend-release.yml`.

The workflow should:

- trigger on tag push for `v*`
- allow manual dispatch
- run on Ubuntu
- install PHP 8.2 and a compatible Node runtime
- validate Composer metadata
- install PHP dependencies without dev packages
- install Node dependencies and run the frontend build
- assemble a clean release directory
- generate a manifest file and SHA256 checksum
- create or update a GitHub Release
- upload the `.tar.gz` package and supporting metadata assets

### Package Contents

Include runtime and build-time output needed for deployment:

- application source code
- `vendor/`
- `public/build/`
- release metadata files
- Artisan entrypoint and Laravel bootstrap files

Exclude development-only or environment-specific files:

- `node_modules/`
- `.git/`
- `.github/`
- `.env`
- `storage/`
- `tests/`
- docs and editor cache files

### Manifest Format

Generate a small JSON manifest for each release with at least:

- release name
- semantic version
- git tag
- commit SHA
- build timestamp
- package filename
- checksum
- build notes or changelog text if available

The manifest should be machine-readable and suitable for future admin-side version checks.

### Versioning Rules

Use tag names as the source of truth for release versioning.

Recommended convention:

- `v1.2.3` for stable releases
- optional pre-release tags such as `v1.2.3-rc.1` if needed later

The workflow should derive the release version from the tag or an explicit input when manually dispatched.

## Operational Notes

The package is intended for Linux server deployment, so `.tar.gz` is preferred over `.zip`.

The current backend already uses PHP 8.2 and Laravel 11, so the release workflow should align with those versions.

The future manual update feature can reuse this exact package format without changing the release process again.

## Testing

Before publishing release automation, verify:

- the workflow YAML is syntactically valid
- the release packaging excludes `node_modules/`
- the package includes `vendor/` and `public/build/`
- the manifest file is generated with the expected fields
- the published release assets match the expected filenames

A later implementation pass should add runtime tests or dry-run checks for the release script if the workflow grows more complex.
