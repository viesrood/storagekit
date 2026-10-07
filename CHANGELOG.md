# Release Notes for StorageKit

## 1.0.1 - 2026-10-07

### Added

- A plugin icon and its mask for the control panel.

## 1.0.0 - 2026-10-07

### Added

- An **S3-compatible storage** filesystem type with a configurable endpoint, for Cloudflare
  R2, Backblaze B2, Wasabi and other providers that speak the S3 API.
- Every setting accepts an environment variable, so project config holds no secrets.
- The filesystem is always private: it has no public URL setting, sends no ACL lookups and
  no server-side-encryption header, and makes no AWS STS call.
- `TemporaryUrls::temporaryUrl()` for short-lived signed download URLs, with an optional
  download name, inline name and content type.
