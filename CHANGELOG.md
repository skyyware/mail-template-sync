# Changelog

All notable changes to this project are documented in this file.

## 0.1.1 - 2026-07-12

- Preserve the release version inside clean ZIP packages so direct Shopware
  installations report the same version as the Git tag.

## 0.1.0 - 2026-07-11

- Add portable filesystem export and merge-oriented import for Shopware mail
  templates and translations.
- Add complete import preflight, dry-run reporting, per-template transactions,
  durable backups, and retention cleanup.
- Add safe CLI commands with explicit bulk confirmation and redacted summaries.
- Add Shopware 6.6/6.7 CI, local quality gates, and verified release packaging.
- Store every locale as five reviewable template files with deterministic
  nullable-field metadata.
- Harden directory containment, locale ambiguity handling, transaction-time
  backups, retention validation, and operational command failures.
- Add real Shopware container, DAL update, and transaction rollback integration
  coverage for both supported compatibility lanes.
