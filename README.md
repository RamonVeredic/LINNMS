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

After source changes have passed staging tests, create a staging tag **on the tested source commit** (e.g. `staging-V1.0.1`), merge that commit into `LINNMS-Staging/master`, then create the **matching production Git tag** on `LINNMS/main`. The production build fails closed if the matching staging tag is absent or is not an ancestor of the staging master branch.

Examples: `staging-V1.0.1` in `LINNMS-Staging` pairs with `V1.0.1` in `LINNMS`. The old `V1.0.0` tag that was accidentally created in staging is not a production trigger anymore.

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
2. Using `LINNMS_READ_TOKEN`, it locates the **matching `staging-vX.Y.Z` or `staging-VX.Y.Z` source tag** in `LINNMS-Staging` and checks out its exact commit SHA, verifying that SHA belongs to staging `master`. It also checks out `LINNMS-protection/main`.
3. It validates the native loader on AMD64 and ARM64, applies a production allowlist, and generates a new 32-byte encryption key.
4. It encrypts all selected PHP and builds the multi-arch Docker image with a native loader. The encoder, source plaintext, test files, build headers, and secrets do not enter the final image.
5. It publishes `nms:X.Y.Z` and `nms:latest` only after the build smoke tests pass.

**Release reproducibility:** the application source commit is pinned by the matching staging tag and embedded as `org.opencontainers.image.revision`. The protection toolchain is still fetched from its `main` branch; it should be pinned to a reviewed commit for fully reproducible release builds. A push to `LINNMS/main` involving build scripts still triggers a no-publish build from current staging `master` for CI validation. Manual workflow dispatch allows an explicit source tag.

**Security limitation:** the auto-generated key is compiled into the native loader and can be extracted by a customer who controls the image. It obstructs casual inspection of encrypted source, not full reverse engineering. PHP 8.5 ABI and end-to-end database, device, HTTP, worker and OPcache behavior must be tested in an actual deployment.

## Required permissions

`LINNMS` GitHub Actions secret:
- `LINNMS_READ_TOKEN`: read access to the private `LINNMS-Staging` and `LINNMS-protection` repositories. No `LINNMS_DISPATCH_TOKEN` is required anymore.

GHCR: The existing `ghcr.io/ramonveredic/nms` package rejected a previous production publish with `permission_denied: read_package`. In package settings → Manage Actions access, grant **RamonVeredic/LINNMS** **Write** access. The staging `dev` package independently requires **RamonVeredic/LINNMS-Staging** **Write** access. Granting repo-level `packages: write` in YAML does not necessarily repair existing GHCR package ACLs.

## Deployment files

Production-only Compose examples are under `deploy/` and reference `ghcr.io/ramonveredic/nms:latest`. They are not copied into the final Docker image.

Never push a real production tag before validating your deployment and access to GHCR, because **tag pushes request publication automatically**. Failed attempts can be diagnosed from the production repository's Actions tab.
