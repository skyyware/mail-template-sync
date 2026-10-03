# Changelog

All notable changes to this project are documented in this file.

## 0.2.1 - 2026-10-03

- Shorten the plugin title to Mail Template Sync in English and German.
- Keep the Composer package, plugin identifier, commands and configuration keys unchanged.

## 0.2.0 - 2026-10-01

- Support Shopware 6.7 only. Use `0.1.1` for existing Shopware 6.6 projects.
- Update development dependencies for Shopware 6.7.15.0.
- Read DAL entities through the collection API instead of deprecated search
  result collection methods.
- Run dependency audits with the local release checks and remove obsolete CI
  assumptions from the tests.

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
