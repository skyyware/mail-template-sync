# Contributing

Bug reports, documentation improvements and pull requests are welcome.
You do not need collaborator access. Fork the repository and open a pull
request against `main`. For a substantial feature, open an issue first to
agree on its scope.

## Development setup

1. Fork and clone the repository.
2. Install the locked dependencies with `composer install`.
3. Add focused tests for each behavior change.
4. Run `bin/check` for unit tests, static analysis, style and package checks.
5. Run `bin/integration` against a disposable Shopware project and database
   for container, DAL or filesystem changes. Set `SHOPWARE_PROJECT_ROOT`,
   `DATABASE_URL` and `EXPECTED_SHOPWARE_CORE_VERSION` for that fixture.
6. Describe the behavior change, reproduction steps and checks in your pull
   request. State any checks you could not run.

Keep changes compatible with Shopware 6.7 on PHP 8.2 or newer. Use synthetic
mail templates. Do not include customer data, credentials or proprietary code.

New releases follow the current stable Shopware major line. Older plugin tags
remain installable, but are no longer maintained. Never change an existing tag.

GitHub Actions is disabled. Maintainers run the release checks locally and
review contributions before merging. See [SECURITY.md](SECURITY.md) to report
a suspected vulnerability privately rather than through a public issue.

By contributing, you agree that your contribution is licensed under the MIT
License included in this repository.
