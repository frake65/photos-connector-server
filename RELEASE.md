# Photos Connector server release procedure

This document describes the controlled release flow for the Nextcloud app
`apple_photos_connector`. It applies to version 0.9.0.

## 1. Prepare and inspect the source

Work from a clean review branch based on `main`. Confirm the version, app ID,
links and compatibility range in `appinfo/info.xml`. Keep private signing keys,
certificates, credentials and local configuration outside the repository.

Run the local checks before creating an archive:

```sh
php tests/lint.php
php tests/run.php
git diff --check
```

## 2. Build and verify the unsigned archive

Build from the repository root:

```sh
sh build-package.sh
```

The output is `.build/server/apple_photos_connector-0.9.0.tar.gz`. Extract it
into a fresh temporary directory and verify:

```sh
php tests/package.php /path/to/staging-parent
php tests/package-runtime.php /path/to/staging-parent/apple_photos_connector
```

Validate `appinfo/info.xml` against the official Nextcloud `info.xsd` and
inspect the archive listing. It must contain exactly one top-level
`apple_photos_connector/` directory and no tests, development tools, build
products, `.git`, `.DS_Store`, AppleDouble files, certificates or private keys.
The unsigned archive must not contain `appinfo/signature.json`.

## 3. Sign locally with the official Nextcloud tooling

Use the official Nextcloud signing workflow with the issued app certificate
and its matching private key. Keep both outside Git, transfer them only to an
isolated signing environment when necessary, use restrictive permissions for
the key, and remove temporary copies after signing. Do not sign by hand and do
not modify the archive after signing.

The resulting signed app must contain `appinfo/signature.json`. Verify that
the signature records the expected app ID and certificate, and run the
official integrity check against the signed app. The package checker permits
the certificate material only inside this signature file; certificate and key
files elsewhere remain rejected.

## 4. Archive and release

Archive the signed app without changing its contents. Record the SHA-256 hash,
version, app ID and signature result. Create the Git tag and public release
only after review of the exact signed archive and its source commit. Attach
only that unchanged archive and retain the hash in the release notes.

## 5. Deployment safety

Before a deployment, identify the exact Nextcloud installation, app path,
version, container and owner/group. Create a timestamped backup outside
`custom_apps`. Deploy only the reviewed signed app archive, preserve user data
and configuration, and follow the migration path supported by the installed
Nextcloud version. Verify app activation, version, logs and API status after the
change. Never use a production server as the signing workspace.
