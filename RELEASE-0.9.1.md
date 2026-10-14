# Photos Connector 0.9.1

This release updates the server-side Nextcloud app `apple_photos_connector`.
It does not include changes to the macOS or iOS companion apps.

## Changes

- Reject malformed album sync source IDs and selection lists instead of
  silently dropping invalid entries.
- Validate album inventory membership lists before writing album or membership
  records. Invalid requests leave the inventory unchanged.
- Report unknown album membership identities in the inventory response.

## Fixes

- Roll back album inventory changes when an unexpected database or processing
  failure occurs.
- Return generic error messages for unexpected album inventory and sync
  failures, without exposing internal exception messages.

## Compatibility

- App ID: `apple_photos_connector`
- PHP: 8.2 or newer
- Declared Nextcloud version: 35 only
- Declared Nextcloud Photos range: 7.0.0–8.0.0
- The existing companion client payload shape remains supported by the server
  controller tests.

The app is declared for Nextcloud 35 only because no Nextcloud 34 test instance
is available. The signed candidate was enabled and passed the official app
integrity check on the available Nextcloud 35.0.2 RC2 test instance with Photos
8.0.0. The instance was restored to app version 0.9.0 after the check. The
controlled end-to-end companion-client upload check recorded as open in 0.9.0
also remains open.

## Upgrade notes

No database migration is introduced in 0.9.1. Install over the existing app
using the normal Nextcloud app update process after the signed package is
published.
