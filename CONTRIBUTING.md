# Contributing to Prospera

Thank you for considering a contribution! This document describes the
standards every pull request must meet before it can be merged.

## Getting started

```bash
composer setup        # install deps, .env, app key, migrate, npm install + build
npm run dev           # Vite dev server
php artisan serve     # app at http://localhost:8000
```

Design notes live in [`docs/`](docs): budgets, enveloppes, ledgers and shared
finances. Read the relevant one before touching its domain — the permission
model in particular is stricter than it looks.

## Code quality: PRs must be thoroughly tested

Every PR must pass the full CI gate locally before it is opened:

```bash
composer ci:check     # format + lint (vp check), vue-tsc, pint, phpstan, artisan test
```

In addition:

- **New features and bug fixes need tests.** Backend changes need PHPUnit
  feature tests; frontend behaviour changes need Playwright e2e coverage when
  the change is user-visible.
- **Run the e2e suite** for UI or flow changes:

    ```bash
    # stop any dev `php artisan serve` first — the suite needs port 8000
    npm run e2e
    npm run e2e:seed -- seed.php   # seed the isolated e2e environment when needed
    ```

    The e2e suite runs against the isolated `.env.e2e` / `database/e2e.sqlite`
    environment, never against your dev database.

- Refactors may not change behaviour; if tests need to change, the PR
  description must explain why.
- Do not disable, skip or weaken existing tests to make a PR pass. If you
  believe a test is wrong, fix or remove it as an explicit, justified change.

## Conventional commits

Commit messages follow the [Conventional Commits](https://www.conventionalcommits.org/)
specification:

```
<type>(<scope>): <short description> (#<issue>)
```

- Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`,
  `ci`, `chore`, `revert`.
- Scope (optional): the area touched, e.g. `ledger`, `budget`, `enveloppe`,
  `ui`, `e2e`.
- Reference the issue it closes or relates to in the message (e.g.
  `fix(ledger): reject duplicate month entries (#42)`). If there is no issue,
  the description must be self-explanatory.
- One logical change per commit; a PR may contain several commits.

Example:

```
feat(budget): warn when draft totals exceed revenue (#57)
```

## Pull requests

- Keep PRs small and focused: one feature, one fix, or one refactor.
- Link the related issues (`Closes #42`).
- Fill in the PR template completely — PRs missing the required sections may
  be closed without review.

### AI usage disclosure (required)

If AI tools were used to produce any part of the contribution — code, tests,
documentation or the PR description itself — the PR **must** disclose it in
the _AI usage_ section, listing each model and its approximate share of the
contribution:

```
- Claude Opus 5.5: 90%
- GPT 6-Luna: 10%
```

If no AI was used, state `None`.

### Review rules for AI-assisted contributions

- **If AI was used at all** (to write or review code), the PR **must** be
  reviewed and merged by a human being. An AI review does not count.
- The only exception is users with **admin rights** on the repository: they
  may review, override and merge their own AI-assisted changes at their
  discretion.
- When branch protection is enabled on this repository, admins are the only
  bypass actors for this rule.

## License

By contributing, you agree that your contributions are licensed under the
project's [CC BY-NC-ND 4.0](LICENSE) license, and you grant the project
maintainers the right to distribute and maintain them as part of the
canonical source. Contributions must target the canonical repository —
please do not publish modified forks; submit your changes here as PRs
instead.
