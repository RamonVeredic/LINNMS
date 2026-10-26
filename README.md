# LINNMS — Production-only build

The application source of truth is **RamonVeredic/LINNMS-Staging** `master`.
This repository owns only the production filter, production Dockerfile, and build workflow.
The encoder/native loader are fetched from **RamonVeredic/LINNMS-protection** `main`.

```
LINNMS-Staging/master --source--> isolated production allowlist/filter
LINNMS-protection/main ---------> offline PHP encoder + native loader build
                                     |
                                 encrypted PHP
                                     |
                                final image only
```

No staging Dockerfiles, Git history, `.github`, docs, testing files, source-seed or staging mount support are copied into the final image. The source-mount branch of the staging init script is removed during packaging. Source is encrypted across runtime, configuration templates, and frontend PHP. CSS/JS/HTML remain cleartext, as required by the browser.

## Required GitHub Action secrets

- `LINNMS_READ_TOKEN`: fine-grained personal access token (read access) to both private repositories `LINNMS-Staging` and `LINNMS-protection`. The normal `GITHUB_TOKEN` for LINNMS may not read those repositories.
- Encryption is automatic: each build generates a fresh 32-byte random key using `openssl rand -hex 32`, encrypts the PHP sources, then compiles that key into the `linnms_loader.so` binary. No `LINNMS_ENCRYPTION_KEY_HEX` GitHub secret is needed.

The workflow `LINNMS Protected Production` is **manual-only**. By default it builds a candidate without pushing. Opting into `publish` pushes **only** `ghcr.io/ramonveredic/nms:protected-candidate` (NOT `latest`) to avoid replacing an operational image prematurely.

## Production deployment definitions

Production compose templates have been moved here from staging and currently target `ghcr.io/ramonveredic/nms:protected-candidate`. They are deliberately not included in the container filesystem. Do not replace `latest` until end-to-end validation is complete. The production repository is the only home for deployment examples.

## Automatic encryption key and security tradeoff

Production CI generates a fresh key for every build. It is **not** stored as a separate file in the final image, and no key-volume setup is required on the customer's deployment. The generated C header and encoder plaintext stay in the CI working directory/build stage; never upload logs, caches, or artifacts containing these files.

**Security limitation:** the key is embedded in the native loader binary and can be recovered by anyone who controls the customer-side Docker image. This protects source from direct viewing, but does **not** guarantee secrecy against reverse engineering. For a stronger custody model, maintain a separate runtime key outside the container and manage it through a dedicated secret store.

## Status and known limitations

**Experimental. Not yet validated end to end.** The native Zend hook requires testing with actual PHP 8.5 Alpine binaries and the deployed nginx/PHP-FPM environment. Smoke tests do not cover database, OLT, SNMP, internal API, or web routes. If the native-loader tests or packaging checks fail, the workflow does not publish.

OPcache is disabled pending compatibility testing. GitHub-hosted runners process plaintext source during build, and require appropriate restricted access; encoded-only Docker context protects distributed images, not the CI host. Use a dedicated trusted runner if this is unacceptable.
