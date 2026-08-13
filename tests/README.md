# Plugin tests

```bash
pnpm --filter @snowseo/wordpress-plugin wp:start    # first run downloads ~1.5 GB, takes a while
pnpm --filter @snowseo/wordpress-plugin wp:verify
```

Needs Docker Desktop running. `wp:verify` checks for that and for a booted
environment before it starts, and tells you which one is missing rather than
failing somewhere inside wp-env.

Exits non-zero on failure, so it works as a gate.

## What `verify.php` covers

A smoke test, not a test suite. 39 assertions:

| # | Section | What it proves |
|---|---------|----------------|
| 1 | classes load | every class file is required and parses; no duplicate declarations |
| 2 | cross-class members | `SnowSEO_Rest_API::api_url()`, the option constants, the static auth and connection helpers all resolve at runtime |
| 3 | routes registered | WordPress accepted all 21 routes and every `callback` / `permission_callback` is `is_callable()` |
| 4 | kses filter | `allow_iframe_in_post_kses` hooks and — critically — **unhooks** by callback identity |
| 5 | publish | a YouTube iframe survives both kses passes, an untrusted iframe and a `<script>` do not, and the filter is left unhooked afterwards |
| 6 | media alt | `attachment_id_from_url` resolves both an exact URL and a `-1024x768` resized variant; alt lands in `_wp_attachment_image_alt`; an unresolvable URL is reported per-item rather than fatal |
| 7 | connect | with the backend mocked via `pre_http_request`: key and connection row stored, `is_connected()` true, activity log written, and `verify_plugin_key` accepts the right key while rejecting a wrong one |

Sections 2 and 3 exist because PHP resolves calls at runtime — `php -l` passes
happily on a call to a method that does not exist. Moving code between classes
silently repoints every `self::`, and that is exactly how two live bugs reached
`main` during the REST split.

## What it does NOT cover

- `/posts/by-url`, `/posts/{id}/update`, `/invalidate`, `/logs` pagination
- taxonomy term resolution, SEO-plugin meta mirroring, duplicate-article handling
- the whole `.htaccess` performance subsystem — apply/revert writes to the site
  root, so drive that by hand through **SnowSEO → Page Speed** and confirm a
  revert leaves the file byte-identical
- `uninstall.php`
- the real SnowSEO backend handshake (only our side of it is mocked)

A pass means "no contradiction found", not "correct".

## Safety

- Refuses to run when `wp_get_environment_type()` is `production`.
- Fixtures (post, attachment) are torn down by a shutdown handler, so a fatal
  midway still cleans up.
- The three options it touches — API key, connection, activity log — are
  snapshotted and **restored**, not deleted. Running this against a dev site
  that is genuinely connected to SnowSEO will not disconnect it.

## Making it a real suite

wp-env already provisions everything needed: a test site on `:8889`, plus
`WordPress-PHPUnit` under `~/.wp-env/<hash>/`. Converting means a `phpunit.xml`
and running through wp-env's `tests-cli` service, which buys isolation,
per-test transactions and CI. Worth doing when this file starts being relied on
rather than merely consulted.

## Shipping

Nothing here reaches the plugin zip. Both `plugin-zip` and `plugin-zip:py` use
allowlists — four root files plus `build/` and `includes/` — so `tests/` is
excluded by construction, same as `stubs/` and `.wp-env.json`.
