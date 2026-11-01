# Web Template

The house Laravel + Inertia/Vue starting point. New projects are cloned from this repo (its
`web/` directory is the git root), so it is kept working and dogfooded rather than hand-assembled
each time. Global conventions live in `~/.claude/CLAUDE.md`; this file is the short list of things
that are specific to the template.

## Two modes

The template runs in one of two modes, switched at runtime by `WORKFLOW_MODE` (`config/workflow.php`):

- **Base mode (`WORKFLOW_MODE=false`, default):** a standalone app with the official Laravel
  Inertia+Vue starter auth (local email + password, registration, reset, verification, passkeys,
  2FA, settings). This is the starting point for standalone apps.
- **Workflow mode (`WORKFLOW_MODE=true`):** login is delegated to Thijssensoftware ID (SSO) and the
  portal switcher is shown. Added under ticket WEB-2; base mode is complete first.

## Quality gates are strict (do not lower them)

- **PHPStan (Larastan) level 10.** Analyses `app/`, `bootstrap/app.php`, `database/`, `routes/`.
  `config/` is intentionally excluded (declarative framework scaffolding). Fix the cause, never add
  baselines, `@phpstan-ignore`, or casts-to-silence.
- **100% test coverage.** `composer test:coverage` runs `php artisan test --coverage --min=100`
  (scoped to `app/` via `phpunit.xml`). New code ships with the tests that cover it. CI runs it with
  xdebug. Herd bundles Xdebug but does not load it, and has no PCOV, so out of the box the command
  fails with "Code coverage driver not available" instead of a percentage. Locally we use PCOV.
  `pecl install pcov` will not work (Herd's PHP has no `phpize` or headers): build PCOV against
  Homebrew's `php@8.4` headers, which are ABI-compatible, then load it with a `pcov.ini` in Herd's
  ini scan dir (`~/Library/Application Support/Herd/config/php/84/`). Herd's PHP only reads that
  dir when `HERD_PHP_84_INI_SCAN_DIR` is exported (Herd adds it to `~/.zshrc`), so shells that skip
  `~/.zshrc` run without PCOV.
- **Architecture tests** (`tests/Unit/ArchTest.php`) enforce the house conventions mechanically:
  strict types everywhere, no `dd`/`dump`/`ray`, no Livewire/Filament, controllers extending the
  base controller, `App\Actions` expose a `handle()` method, models extend Eloquent, form requests
  extend the framework request, enums backed and confined to `App\Enums`. **Controllers, actions,
  services, policies and form requests must all be `final`** (models and providers are exempt:
  Eloquent and the framework both extend them). If you need to vary behaviour, inject a
  collaborator rather than subclassing.
- `composer ci:check` runs the JS gate (ESLint, Prettier, `vue-tsc`, Vitest), Pint, PHPStan and
  Pest. CI additionally runs `composer test:coverage`.
- **Vitest** (`npm run test:js`) covers composables and components. Specs live in
  `__tests__/` next to what they test and are named `*.test.ts`; `vitest.config.ts` runs jsdom
  everywhere so a component spec never has to remember an annotation. Note the 100% coverage gate
  is PHP-only, so the frontend is gated on tests passing, not on lines covered.

## Must know before editing

- **Wayfinder route helpers are generated at build.** `resources/js/routes` and
  `resources/js/actions` are gitignored and produced by `npm run build`. If an `@/routes/...` import
  won't resolve, run the build.
- **Strict types are enforced by Pint** (`declare_strict_types` rule), so `vendor/bin/pint` adds the
  declaration; don't hand-write it inconsistently.
- **`$request->user()` is nullable at level 10.** On `auth`-protected routes, narrow it with
  `abort_unless($user instanceof User, 403)` before use (see the settings controllers).
- **Only APP_URL's host is served outside `local`.** `bootstrap/app.php` pins Laravel's
  `trustHosts` to that one name, without subdomains, so a request for any other Host gets a 400
  before it is routed (WEB-38). Production's `APP_URL` must therefore be the vhost's name exactly,
  and an app that answers on a second hostname adds it to that list. `local` and the test suite
  skip the check, so `*.test` is unaffected; `tests/Feature/TrustedHostTest.php` runs as production.
- **Health check:** `GET /health` returns `{ status, app, version, database }` for the `status`
  monitor (200 healthy, 503 when the DB is unreachable). Laravel's built-in `/up` is what
  app-deploy checks after a release, so `CheckDatabaseOnHealthCheck` makes it answer 500 when the
  DB is unreachable too, and a release that cannot reach it is switched back (WEB-34).
- **API tokens:** Sanctum personal access tokens, managed at `settings/api-tokens` (list, create
  with a one-time reveal, revoke). The pages sit behind `RequirePassword`, since a token is a
  full-access credential. In workflow mode users have no password, so confirming is a round
  trip through ID that must sign the user in again after it was asked for
  (`App\Actions\Workflow\ConfirmIdentityThroughId`, WEB-28), and every local credential route
  (passkeys, two-factor, password change, the Security page) answers 404 there
  (`BlockLocalCredentials`). `routes/api.php` ships exactly one route, `GET /api/user` behind
  `auth:sanctum`, so the tokens authenticate against something real; an app builds its API out
  from there. When testing the API, do **not** `actingAs()` first: `auth:sanctum` falls back to
  the web guard, so a lingering session authenticates the request and the token is never exercised.
  A token expires after `CreateApiToken::LIFETIME_DAYS` (90), with `sanctum.expiration` as the
  backstop for one minted any other way, and its plaintext starts with a prefix derived from
  `APP_NAME` so secret scanners can spot a leaked one. `RevokeApiTokensOnLostAccess` deletes a
  user's tokens when ID sends `access.revoked`, but not on a plain logout, which ID sends per
  session. Every API route goes through the `api` limiter, 60 a minute per token (WEB-32).
- **Reporting ships off, and there are two of them.** `thijssensoftware/flare-client` catches what
  the runtime noticed; `thijssensoftware/snag-client` lets a person report what it did not, from
  inside the page. Both self-register and both default to off, because a fresh clone has no project
  in flare, no application in snag, and no keys, so enabling them would fire at two ingest endpoints
  for nothing.

  To turn flare on: register the app (`flare:project "Name" --tracker=KEY` on the flare box), put
  its key in `FLARE_KEY`, set `FLARE_ENABLED=true`, confirm with `php artisan flare:test`.
  Exceptions, failed jobs, failed scheduled tasks and non-zero command exits then report with no
  code in the app.

  To turn snag on: register the application in snag, then set `SNAG_ENABLED=true`, `SNAG_URL`,
  `SNAG_KEY`, `SNAG_SECRET` and `SNAG_PSEUDONYM_SALT`. The last two are two secrets deliberately:
  snag holds the ingest secret and must never hold the salt, which is what keeps a reporter's
  pseudonym from being reversible by snag itself. `@snag` already sits in `app.blade.php` and
  prints nothing until then, or for a signed-out visitor.

  **Constraints on the house packages are `^0.3`, not `^0.1`, and that matters.** Composer's caret
  is restrictive to the minor on a 0.x version, so `^0.1.0` resolves to `>=0.1.0 <0.2.0` and can
  never upgrade. This template pinned flare-client and id-client that way, and six apps sat on
  id-client 0.1.0 with back-channel single logout broken as a direct result (WEB-26, ATLAS-20).
  When these packages reach 1.0 the constraint can relax; until then, widen it deliberately.

- **`X-Request-Id` works whether or not flare is on.** request-id stamps every request, job and
  command and echoes the header back, which is how a snag report is traced to the exception behind
  it. Only a ULID or UUID is adopted from an incoming header; anything else is replaced, because an
  arbitrary client string would land in log files, alert mail and the flare UI.

## Giving a new app its own identity

A clone starts deliberately colourless: the stock shadcn neutral base, and a placeholder mark in
`AppLogoIcon.vue`. Both are meant to be replaced in the app's first week, not shipped. Four apps
ran for months on the defaults and ended up indistinguishable from each other, which is the
failure this section exists to prevent.

1. **Take the accent from Thijssensoftware ID.** Each app's colour lives once in ID's application
   catalog (`accent`, `#RRGGBB`), which is what paints its tile in the portal switcher. Reading it
   from there is what keeps the tile, the favicon and the app itself from disagreeing. A new app
   picks a colour nothing else in the catalog uses, and one that sits **outside its own semantic
   ramp**: an error tracker whose brand is the same orange as its "critical" chip makes every
   screen ambiguous.
2. **Generate the tokens.**

   ```bash
   php bin/palette.php "#0E7490"
   ```

   It prints the `:root` and `.dark` blocks for `resources/css/app.css`; paste them over the two
   that are there. Every variable keeps its shadcn name, so the component library follows without
   a component changing. The script also reports the primary's contrast against its own
   foreground and says so when it fails AA.
3. **Replace the mark.** `AppLogoIcon.vue` is a placeholder frame. Draw the app's own mark from
   the same shape as its favicon. Every caller passes `fill-current`, so it must be filled rather
   than stroked.
4. **Match the rest.** `resources/views/app.blade.php` hardcodes the anti-flash background as an
   `oklch()` literal in an inline style; it has to move with `--background`, or every page load
   flashes the wrong colour before the stylesheet lands. Add a `theme-color` meta with the accent
   while you are there. `AppSidebar.vue`'s `footerNavItems` point at this template's repo and
   should point at the app's own.

## Local dev

- `composer dev` runs the server, Vite and logs together.
- Tests: Pest with `RefreshDatabase` on in-memory SQLite (`php artisan test`).

## Deploying

`.github/workflows/deploy.yml` is `workflow_dispatch` only; production is deployed on purpose,
never on push. The workflow itself does nothing but SSH in: the deploy runs on the server as
`app-deploy`, which the CI key is pinned to, so a leaked secret can redeploy this app's `main`
and nothing else. It needs four repo secrets: `DEPLOY_SSH_HOST`, `DEPLOY_SSH_USER`,
`DEPLOY_SSH_KEY` and `DEPLOY_SSH_KNOWN_HOSTS`.

`app-deploy` builds the release next to the live one (composer, npm, migrations, caches) and
switches a symlink, so there is no maintenance window and no `artisan down`. If `/up` fails
afterwards it switches back by itself. Migrations are not reverted, so keep them backwards
compatible with the release before.

**The app has to exist in the infra playbook first.** One entry in
`~/Projects/infra/ansible/group_vars/all/apps.yml` creates its Linux user, php-fpm master,
vhost, workers, scheduler and CI key; without it there is nothing to deploy to. Workers and
php-fpm are restarted by `app-deploy`, not by anything in this repo.
