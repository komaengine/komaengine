# Release supply chain

Every KomaEngine release is built by one GitHub Actions workflow from one exact
Git commit, for both `linux/amd64` and `linux/arm64`, with pinned build and
runtime images. The release ZIP, the container image, and the evidence that
describes them are signed without any long-lived key, and the signatures are
recorded in Sigstore's public transparency log. This page explains how to check
a download and how the release is produced.

## Verify a release

You need [Cosign](https://docs.sigstore.dev/cosign/system_config/installation/)
3.0 or newer. Every release is signed by the release workflow running for its
own tag, so for version `1.0.0`:

```bash
IDENTITY=https://github.com/komaengine/komaengine/.github/workflows/release.yml@refs/tags/v1.0.0
ISSUER=https://token.actions.githubusercontent.com
```

**Container image.** Verify the image you are about to run. Use the digest from
the release notes when you can, so that what you verify is exactly what you
pull:

```bash
cosign verify ghcr.io/komaengine/komaengine:1.0.0 \
  --certificate-identity "$IDENTITY" --certificate-oidc-issuer "$ISSUER"
```

**Release ZIP.** Download the ZIP and `artifact.sigstore.json` from the release
page, then:

```bash
cosign verify-blob komaengine-YYYYMMDD-HHMMSS.zip \
  --bundle artifact.sigstore.json \
  --certificate-identity "$IDENTITY" --certificate-oidc-issuer "$ISSUER"
```

Compare `sha256sum` (or `shasum -a 256`) of the ZIP with the value in the
release notes as well; the shared-hosting guide checks it again on the server.

**Evidence.** The SBOMs, provenance, and image lock are covered by
`SHA256SUMS`, which is itself signed:

```bash
cosign verify-blob SHA256SUMS \
  --bundle evidence.sigstore.json \
  --certificate-identity "$IDENTITY" --certificate-oidc-issuer "$ISSUER"
sha256sum --check SHA256SUMS
```

If any check fails, do not install the download, and report it through the
[security policy](../SECURITY.md).

## What a release contains

Each GitHub Release carries these files, kept for the life of the release:

| File | What it is |
|---|---|
| `komaengine-YYYYMMDD-HHMMSS.zip` | The flat-file package for shared hosting |
| `artifact.sigstore.json` | Signature bundle for the ZIP |
| `SHA256SUMS`, `evidence.sigstore.json` | Checksums of the evidence files, and their signature bundle |
| `provenance.intoto.json` | in-toto Statement v1 with SLSA Provenance v1, binding the ZIP SHA-256, image digest, source commit, version, build ID, lock hashes, and SBOM hashes |
| `flatfile.spdx.json` | SPDX inventory of the ZIP, including Composer dependencies |
| `npm.spdx.json` | SPDX inventory of the npm build dependencies |
| `image.spdx.json`, `image-amd64.spdx.json`, `image-arm64.spdx.json` | SPDX inventories of the image, including operating-system packages |
| `image-lock.env` | The exact build, runtime, and tool images used |
| `image-index-digest.txt`, `image-digest-amd64.txt`, `image-digest-arm64.txt` | The signed multi-architecture image digest and each platform's digest |
| `image-signature-verification.json` | The workflow's own verification of the image signature |
| `trivy-amd64.json`, `trivy-arm64.json` | Vulnerability scan of each platform image |
| `doctor-amd64.log`, `doctor-arm64.log` | Native install and operational doctor run on each platform |

The images also carry BuildKit SPDX and maximum-mode provenance attestations,
attached to their digests in the registry.

## How a release is produced

`.github/workflows/release.yml` runs when a `v*` tag is pushed to
`komaengine/komaengine`. `scripts/release/release-identity.sh` first refuses to
continue unless the tag is a full version, equals `v` plus the `VERSION` file,
and points at the checked-out commit, and unless the signing identity will be
the one documented above. Then:

1. Each architecture is built natively on its own GitHub-hosted runner from that
   commit, installed into a fresh MariaDB-backed container set, and checked
   with the operational doctor.
2. Each platform image is scanned; any critical or high finding with a fix
   available stops the release.
3. The two platform images are assembled into one index and tagged. A stable
   release is tagged `X.Y.Z`, `X.Y`, `X`, and `latest`; a prerelease only
   `X.Y.Z-…`. The index is signed and the signature verified. The workflow also
   proves that an unsigned platform digest does not verify.
4. The ZIP is built from the same commit, inspected, and described by SBOMs and
   provenance that are generated and then independently verified. The ZIP and
   `SHA256SUMS` are signed and verified, and a byte-modified copy of the ZIP is
   shown not to verify.
5. A draft GitHub Release is opened with every file listed above, refusing to
   continue if any is missing or if the changelog has no entry for the version.
   A maintainer publishes it after review.

Run anywhere other than the public repository, the same workflow is a manual
rehearsal: it uses test image names and opens a draft prerelease, and it never
runs for a tag push. No signing key or registry password exists; signing uses
GitHub's OIDC token.

## Locked inputs

| Purpose | Version |
|---|---|
| Front-end build | Node 22.23.2, Debian Bookworm slim |
| Runtime | PHP 8.4.26 FPM, Debian Bookworm |
| PHP compatibility tests | PHP 8.3.35, 8.4.26, and 8.5.11 CLI |
| PHP dependency build | Composer 2.9.7 |
| Primary database | MariaDB 11.8.9 on Ubuntu Noble |
| Compatibility database | MySQL 8.4.11 |
| Optional database UI | Adminer 6.1.0 standalone |
| Browser QA | Playwright 1.63.0 on Ubuntu Noble |
| SBOM generator | Syft 1.52.0 |
| Container vulnerability scan | Trivy 0.74.0 |
| Secret scan | Gitleaks 8.30.1 |
| Workflow lint | actionlint 1.7.12 |

Each entry is a readable version plus its multi-architecture manifest digest in
`scripts/release/image-lock.env`. `tests/Release/image-pins-smoke.sh` enforces
that the Dockerfiles and Compose files match the lock and that no trusted path
uses a `latest` or other mutable tag; its scanner fails closed on tool errors.

## Vulnerability scan gate

The release workflow scans each native image with the pinned Trivy and fails on
any critical or high finding for which a fix exists. Findings with no fix
available do not fail the gate.

`tests/Release/image-scan-smoke.sh` applies the same gate locally to the image
built from an exact commit, and fails if its options drift from the workflow's.
Run it before relying on a pin and before tagging a release: pinned images do
not receive distribution security updates, so a pin that passed earlier starts
failing as soon as its packages are fixed upstream. Set `KOMA_TEST_IMAGE` to
scan an image that already exists locally.

The local run covers the machine's own architecture only, and neither gate
scans the database, Adminer, or build-stage images.

## Updating a pin

1. Select a specific upstream patch version from the publisher's official
   registry or release page. Never select `latest`.
2. Resolve the tag's multi-architecture manifest digest with
   `docker buildx imagetools inspect IMAGE:VERSION`.
3. Update the tag and digest in `scripts/release/image-lock.env` and every
   Dockerfile or Compose location enforced by the pin smoke.
4. Run:

   ```bash
   tests/Release/image-pins-smoke.sh --resolve
   tests/Release/image-scan-smoke.sh
   docker compose --env-file ../koma.docker.env up -d --build --wait
   docker compose --env-file ../koma.docker.env exec app \
     gosu www-data php artisan koma:doctor --mode=operational
   ```

5. Run the PHP and release tests affected by that image. A runtime or builder
   pin is accepted only after a release rehearsal passes natively on both
   `linux/amd64` and `linux/arm64`; an emulated local build is diagnostics, not
   architecture acceptance.

Upgrade and rollback drills preserve each historical application tree but
replace the older Dockerfile's mutable Node, PHP, and Composer build references
inside its disposable export. The replacement is strict and fails if the known
historical lines or current locked lines are not present; it never edits a Git
object or the working checkout.

## Checking the tooling

- `tests/Release/release-workflow-smoke.sh` checks the release identity rules
  against throwaway repositories, static safety properties of the workflow
  (pinned actions, read-only default permissions, draft-only releases, no
  secrets beyond `GITHUB_TOKEN`), and runs actionlint.
- `tests/Release/supply-chain-workflow-smoke.sh` runs the workflow's real
  image-assembly step against valid, malformed, and failed registry responses.
- `tests/Release/supply-chain-smoke.sh` generates and verifies local evidence
  for an exact commit, and proves that byte-tampered artifacts and
  checksum-updated but mismatched provenance are rejected.

To generate and verify evidence yourself for an exact committed tree:

```bash
scripts/release/generate-supply-chain.sh \
  --source-ref FULL_COMMIT \
  --artifact /path/to/komaengine.zip \
  --image komaengine:exact-test \
  --output /safe/output/evidence

scripts/release/verify-supply-chain.sh \
  --source-ref FULL_COMMIT \
  --artifact /path/to/komaengine.zip \
  --image komaengine:exact-test \
  --evidence /safe/output/evidence
```

The verifier fails closed on changed artifact bytes, evidence checksums,
source and build metadata, dependency locks, SBOM hashes, or provenance
subjects.

## Retention

Release assets are never deleted or replaced. Superseding evidence requires a
new release. Image signatures and BuildKit attestations stay attached to the
immutable image digests in the registry.
