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
- Declared Nextcloud range: 34–35
- Declared Nextcloud Photos range: 7.0.0–8.0.0
- The existing companion client payload shape remains supported by the server
  controller tests.

The release candidate has passed the repository's standalone tests and package
checks. Those tests do not replace runtime validation on Nextcloud 34 and 35;
the declared range should be confirmed on those versions before publication.
The controlled end-to-end companion-client upload check recorded as open in
0.9.0 also remains open.

## Upgrade notes

No database migration is introduced in 0.9.1. Install over the existing app
using the normal Nextcloud app update process after the signed package is
published.
