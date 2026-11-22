# Clean deployment validation

[Español](TESTS.md) · English

Verified on November 21, 2026 using Docker Desktop, Linux x86_64 containers and separate disposable volumes.

- Full build of PHP, Imagick, FFmpeg, yt-dlp and Deno: passed.
- MariaDB and application startup: both healthy.
- Creation of 31 empty tables, initial administrator and real hardlink check: passed.
- Login, incorrect-password rejection and mandatory initial password change: passed.
- Folder/note creation, exact file download, public sharing and receiving links over local HTTP: passed.
- Settings, media capabilities and English catalog responses: passed.
- Direct access to configuration and internal resources: blocked as intended.
- Restart: changed password, account and test file persisted.
- PowerShell and Bash installer syntax: passed.

The ARM64 option is prepared for 64-bit Raspberry Pi. This review does not include a native ARM64 build of the new package. Compose configuration validation must not be interpreted as an architecture test. A real YouTube download has not been tested in this clean environment.

The test environment used its own project name, port and volumes. Production data was never imported. Test credentials were generated outside distributable files. These checks are not a comprehensive security audit or a production-readiness certification.
