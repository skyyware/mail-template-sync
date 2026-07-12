# Skyy Mail Template Sync

Skyy Mail Template Sync keeps Shopware mail templates in portable, reviewable
files. It supports Shopware 6.6 and 6.7 on PHP 8.2 or newer.

## Installation

Install the packaged ZIP through the Shopware extension manager, or place the
plugin in `custom/plugins/SkyyMailTemplateSync`. Then refresh, install, and
activate it with the normal Shopware plugin lifecycle.

## Commands

Export one template by technical name:

```console
bin/console skyy:mail-template:export order_confirmation_mail
```

Export every template:

```console
bin/console skyy:mail-template:export --all
```

Preview an import without backups or database writes:

```console
bin/console skyy:mail-template:import --all --dry-run
```

Import one template, or confirm an interactive bulk import:

```console
bin/console skyy:mail-template:import order_confirmation_mail
bin/console skyy:mail-template:import --all
```

Automation must explicitly acknowledge bulk writes:

```console
bin/console skyy:mail-template:import --all --no-interaction --force
```

Use `--directory=/absolute/path` or a path relative to the Shopware project to
override the default `custom/mail-templates` directory. Plugin configuration
must be project-relative. Existing path ancestors and symbolic links are
canonicalized before use; the project root, its `vendor` tree, this plugin's
package root, and relative symlink escapes are rejected.

The plugin settings provide the default storage directory
(`SkyyMailTemplateSync.config.storageDirectory`) and backup retention in days
(`SkyyMailTemplateSync.config.backupRetentionDays`, default `365`).

Command output contains only counts, technical names, and changed field paths.
Template subjects and bodies are never printed. Imports validate all selected
files and targets before writing; changed templates are backed up under
`var/skyy-mail-template-sync/backups` and updated in individual transactions.
Each import transaction uses one parameterized DBAL `SELECT ... FOR UPDATE` on
the system-default `mail_template` row solely to coordinate concurrent writes.
All entity reads, translation reads, and entity writes remain Shopware DAL
operations.

## Portable Format

Each system-default template uses this portable layout:

```text
<technical-name>/
  manifest.json
  <locale>/
    subject.twig
    sender-name.twig
    description.txt
    html.twig
    plain.twig
```

The deterministic manifest contains exactly schema version `1`, the technical
name, the system-default marker, sorted locales, and a `nullFields` map; extra
top-level metadata is rejected. All five files are always present. A null field
has an empty file and is named explicitly in `nullFields`, preserving the
difference between `null` and an intentional empty string. Manifests contain no
Shopware entity or language IDs.

Export is authoritative and removes stale locale directories. Import is
merge-oriented and leaves Shopware-only locales intact. Bulk import rejects
missing, empty, or malformed roots instead of silently processing zero bundles.

## Development

Install dependencies and run every local quality gate:

```console
composer install
bin/check
```

`bin/check` validates Composer metadata, runs unit tests, PHPStan, the style
check, Git whitespace checks, and package verification. `bin/package` creates
`build/SkyyMailTemplateSync-0.1.1.zip` with a single
`SkyyMailTemplateSync/` root and no development-only files.

Run the real Shopware container and DAL transaction tests against an isolated
database. The Shopware test bootstrap appends `_test` to the database name:

```console
DATABASE_URL='mysql://root@127.0.0.1:3306/skyy_mail_sync' \
SHOPWARE_PROJECT_ROOT='/path/to/shopware' \
bin/integration
```

The runner temporarily exposes this checkout at
`custom/plugins/SkyyMailTemplateSync`, refuses unrelated occupied targets, and
removes only a symlink it created.

## License

MIT. See [LICENSE](LICENSE).
