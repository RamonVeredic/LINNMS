# LINNMS — Production Image Repository

This repository owns **production-only Docker build, source filtering, encryption integration, validation and GHCR release**. It does **not** store raw application PHP.

Repositories:
- `RamonVeredic/LINNMS-Staging`: application source (`master`) and staging-only image workflow.
- `RamonVeredic/LINNMS`: production release tags, Dockerfile, CI, Compose templates, published image.
- `RamonVeredic/LINNMS-protection`: PHP 8.5 offline encoder and native Zend loader.

## Tag-triggered release (separate repositories)

| Repository where you create the tag | Tag examples | Result |
| --- | --- | --- |
| **LINNMS-Staging** | `staging-v1.0.1` or `staging-V1.0.1` | Builds `ghcr.io/ramonveredic/dev:1.0.1` and `dev:latest` in staging CI |
| **LINNMS** | `v1.0.1` or `V1.0.1` | Builds **and attempts to publish** `ghcr.io/ramonveredic/nms:1.0.1` and `nms:latest` in production CI |

**Production tags are created in LINNMS, not LINNMS-Staging.** Source development and merging continue in LINNMS-Staging. Its old production-dispatch workflow was removed. There is no cross-repository dispatch token or release workflow in staging.

After source changes have passed staging tests and are merged to `LINNMS-Staging/master`, create the production Git tag on **LINNMS/main**:

```bash
git clone git@github.com:RamonVeredic/LINNMS.git
cd LINNMS
git switch main
git pull --ff-only origin main
git tag V1.0.1
git push origin V1.0.1
```

Create a **new** release version each time. Production tag push triggers `.github/workflows/production.yml` in this repository. Releasing a GitHub Release with a new Git tag also works.

A normal change to LINNMS production tooling under `main` runs a no-publish validation build; a normal merge of source code in LINNMS-Staging does **not** publish production.

## Build chain and security controls

1. LINNMS CI checks out the tagged LINNMS production **build recipes**.
2. Using `LINNMS_READ_TOKEN`, it checks out `LINNMS-Staging/master` and `LINNMS-protection/main`. It records the source commit SHA and labels the Docker image with the source revision.
3. It validates the native loader on AMD64 and ARM64, applies an explicit production allowlist, and generates a fresh 32-byte symmetric key.
4. It encrypts the selected PHP files and builds a multi-arch Docker image with an embedded native loader. The generated header, encoder, staging Dockerfile, test files, CI secrets, and plaintext application source do not enter the final runtime image.
5. Both GHCR release tags are pushed only if the production Docker build and its smoke checks succeed.

**Current reproducibility limitation:** a production Git tag pins the *LINNMS build recipes*, but automatic tagged builds take whichever staging `master` SHA exists when the build starts. The SHA is recorded in the image metadata. Retrying a tag later can therefore select newer source. For a strictly immutable source release, use the manual production workflow with `source_tag` (tag in LINNMS-Staging) and optionally `source_sha`; that workflow verifies the source tag belongs to staging master. Immutable release-source pinning for fully automatic production tags is future work.

**Security limitation:** the auto-generated key is compiled into the native loader and can be extracted by a customer who controls the image. It obstructs casual inspection of encrypted source, not full reverse engineering. PHP 8.5 ABI and end-to-end database, device, HTTP, worker and OPcache behavior must be tested in an actual deployment.

## Required permissions

`LINNMS` GitHub Actions secret:
- `LINNMS_READ_TOKEN`: read access to the private `LINNMS-Staging` and `LINNMS-protection` repositories. No `LINNMS_DISPATCH_TOKEN` is required anymore.

GHCR: The existing `ghcr.io/ramonveredic/nms` package rejected a previous production publish with `permission_denied: read_package`. In package settings → Manage Actions access, grant **RamonVeredic/LINNMS** **Write** access. The staging `dev` package independently requires **RamonVeredic/LINNMS-Staging** **Write** access. Granting repo-level `packages: write` in YAML does not necessarily repair existing GHCR package ACLs.

## Deployment files

Production-only Compose examples are under `deploy/` and reference `ghcr.io/ramonveredic/nms:latest`. They are not copied into the final Docker image.

Never push a real production tag before validating your deployment and access to GHCR, because **tag pushes request publication automatically**. Failed attempts can be diagnosed from the production repository's Actions tab.
