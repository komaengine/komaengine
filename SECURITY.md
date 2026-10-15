# Security Policy

## Reporting a vulnerability

Please do not open a public issue, discussion, or pull request for a suspected
vulnerability.

Report it privately through GitHub:
[**Report a vulnerability**](https://github.com/komaengine/komaengine/security/advisories/new)
(the **Security** tab of this repository). Only the maintainers can see the
report while it is investigated and fixed. If you cannot use GitHub, email
**security@komaengine.com** instead.

Please include:

- the affected KomaEngine version (shown under **Admin → Updates**, in the
  `VERSION` file, or with its build ID in `KOMA-BUILD-METADATA.txt`) and how it
  is deployed (Docker Compose, shared hosting, or another setup);
- the steps to reproduce the problem and what an attacker could achieve;
- any relevant logs, with secrets, passwords, tokens, and personal data
  removed.

We aim to acknowledge a report within seven days, keep you informed while we
work on it, and credit you in the advisory if you wish. Please give us a
reasonable opportunity to release a fix before disclosing the issue publicly.

## Supported versions

Security fixes are made for the latest Community Edition release. Upgrade to
it before reporting a problem that may already be fixed; the
[changelog](CHANGELOG.md) lists what each release contains.

## Scope

In scope: the KomaEngine core, its installer, the built-in themes, the bundled
example plugin, the release ZIP, and the published container image.

Out of scope: third-party themes and plugins, which their authors maintain;
the configuration of a particular server or hosting account; and issues that
require an already compromised administrator account or server.

## Verifying what you run

Release artifacts are signed and come with a software bill of materials and
build provenance. The [supply-chain guide](docs/SUPPLY_CHAIN.md) explains how
to verify a download before you install it.
