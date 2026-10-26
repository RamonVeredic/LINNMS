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
- `LINNMS_ENCRYPTION_KEY_HEX`: 64 hex characters generated securely once; kept private. E.g. generate locally with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Never store this value in Git, images, artifacts or logs.

The workflow `LINNMS Protected Production` is **manual-only**. By default it builds a candidate without pushing. Opting into `publish` pushes **only** `ghcr.io/ramonveredic/nms:protected-candidate` (NOT `latest`) to avoid replacing an operational image prematurely.

## Deployment prerequisite (not automated)

The same hex key must be mounted as a *read-only file* at
`/run/secrets/linnms_protection_key`, readable by the PHP CLI worker and PHP-FPM user `abc`. Loss of the key makes encrypted PHP unavailable. A customer with root access can extract the key; this does not prevent reverse engineering.

## Status and known limitations

**Experimental. Not yet validated end to end.** The native Zend hook requires testing with actual PHP 8.5 Alpine binaries and the deployed nginx/PHP-FPM environment. Smoke tests do not cover database, OLT, SNMP, internal API, or web routes. If the native-loader tests or packaging checks fail, the workflow does not publish.

OPcache is disabled pending compatibility testing. GitHub-hosted runners process plaintext source during build, and require appropriate restricted access; encoded-only Docker context protects distributed images, not the CI host. Use a dedicated trusted runner if this is unacceptable.
