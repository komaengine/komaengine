# Contributing to KomaEngine

Thank you for helping. Bug reports, documentation fixes, themes, plugins, and
code are all welcome.

## Before you start

- **Security problems** go through the [security policy](SECURITY.md), never a
  public issue or pull request.
- **Bugs and ideas** start as an issue, using the templates. For anything larger
  than a small fix, please wait for a maintainer to agree on the approach before
  you write code; it saves everyone a rewrite.
- **Themes and plugins** you have published can be submitted for a listing with
  the extension template.

Please follow the [code of conduct](CODE_OF_CONDUCT.md) everywhere in the
project.

## Development setup

KomaEngine is developed with Docker Compose, which matches how it runs in
production. Follow the [Docker guide](docs/install/docker.md): keep the env file
outside the checkout (the guide uses `../koma.docker.env`), then build and start
the stack:

```bash
docker compose --env-file ../koma.docker.env up -d --build --wait
```

Run Artisan inside the container, for example:

```bash
docker compose --env-file ../koma.docker.env exec app \
  gosu www-data php artisan koma:doctor --mode=operational
```

Do not run a host PHP server, queue worker, scheduler, or Vite dev server next
to the stack. If you prefer a host-native setup instead, see
[local development](docs/install/local.md).

## Tests

Run the PHP suite in a disposable container; no PHP or Composer is needed on
your machine:

```bash
scripts/test-in-docker.sh              # PHP 8.4
scripts/test-in-docker.sh 8.3          # or 8.5
scripts/test-in-docker.sh 8.4 --filter=Installer
```

Check style with Laravel Pint the same way:

```bash
KOMA_TEST_COMMAND='vendor/bin/pint --test' scripts/test-in-docker.sh
```

Release and browser checks live in `tests/Release/`. Each builds from a
committed revision in throwaway containers, for example
`tests/Release/browser-qa-smoke.sh` for the Chromium, Firefox, and WebKit
journeys, and `tests/Release/build-flatfile-smoke.sh` for the shared-hosting
ZIP. Run the ones that cover what you changed.

## Making a change

- Keep a pull request to one purpose, and match the style of the code around
  it. Avoid unrelated refactoring.
- Add or update tests for any change in behavior.
- Update the documentation when behavior, configuration, or a command changes.
- The core does not collect analytics or call outside services. Integrations
  with third parties belong in plugins.
- Commit messages follow `type(scope): subject`, for example
  `fix(installer): explain an unwritable SQLite path` or
  `docs: clarify the Docker env file`.

## Licensing

KomaEngine is MIT-licensed. By submitting a contribution, you agree that it is
licensed under the [MIT License](LICENSE) and that you have the right to submit
it.
