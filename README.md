# flatfile-php-cms

A small CMS for PHP shared hosting that has no database, no Composer step and no build pipeline. Content lives in files that cannot be read over HTTP.

![The admin dashboard](docs/images/dashboard.png)

<!-- Replace docs/images/dashboard.png — see docs/images/README.md for what to capture -->

## Why this exists

I built a custom CMS four times for four client sites. The cheapest hosting those clients would pay for gives you PHP, a filesystem, and not much else — MySQL is often an upsell, SSH usually isn't available, and Composer may not exist on the box.

The obvious answer is to store content in JSON files. The trap is what happens next: you put `content.json` in a `data/` directory and protect it with an `.htaccess` that denies access. That works right up until it doesn't. `.htaccess` is ignored on nginx, ignored when `AllowOverride` is off, and gone entirely if the file is missed during an upload. In each case `https://yoursite.com/data/admin.json` serves your admin password hash to anyone who asks.

I shipped that bug. The fix is in the first line of every data file here:

```php
<?php exit; ?>
{ "site_name": "…" }
```

The store is a `.php` file. Requested directly, PHP executes it, hits `exit`, and returns an empty body. The reader strips that first line and parses the JSON underneath. The `.htaccess` is still there as the cheaper first check — but the protection no longer *depends* on it.

That principle runs through the whole codebase: **defence that does not rely on the host being configured correctly.**

## Features

- **Pages and posts** — draft and published states; the public site only reads published content, so an unfinished edit cannot leak onto the live site
- **Automatic revisions** — every save copies the previous version to `data/revisions/` before overwriting, keeping the last 20
- **Media library** — uploads validated by content sniffing rather than extension, renamed to random hex, and re-encoded to strip EXIF (including the GPS coordinates in phone photos)
- **Contact form** — honeypot-trapped, stored in the admin rather than emailed
- **Generated `sitemap.xml` and `robots.txt`**
- **Single admin account** — no public sign-up route, bcrypt hashing, and a 15-minute lockout after 5 failed attempts
- **CSRF tokens** on every state-changing request, compared in constant time
- **Allow-list HTML sanitising** — anything outside a fixed tag list is stripped on save, including every event handler and every scheme other than `http`, `https` and `mailto`
- **CSP without `unsafe-inline` for scripts**, plus `nosniff`, `X-Frame-Options` and a deliberately short HSTS `max-age`

## Quick start

Requires **PHP 8.2 or newer**. Nothing else — no database, no Composer, no Node.

```bash
git clone https://github.com/Subramanyam2312/flatfile-php-cms.git
cd flatfile-php-cms
php -S localhost:8000 -t public public/index.php
```

Open `http://localhost:8000/admin`. It redirects to a one-time setup page where you create the single admin account, then closes that page permanently.

Create a page with the slug `home` and publish it — that becomes the front page. A page with the slug `contact` renders a contact form automatically.

These commands were run on a clean clone before this README was written.

## Tests

```bash
php tests/run.php           # everything
php tests/run.php Html      # one group
```

84 tests, 214 assertions, no dependencies — the runner is 100 lines in `tests/bootstrap.php` and `tests/run.php`. It exits non-zero on failure, so it can gate a deploy script.

**Why not PHPUnit.** This project's claim is that it runs on a host with nothing installed. A suite you cannot run without `composer install` first undercuts that for exactly the people most likely to be evaluating it. The trade is real — no mocking, no data providers, no coverage report — and if the suite ever needs those it has outgrown the runner and should move to PHPUnit as a dev dependency.

The tests are adversarial where it matters. `HtmlTest` encodes bypasses that defeat a naive sanitiser: case-mixed `JaVaScRiPt:`, schemes padded with tab and newline, `data:` URIs, event handlers, `<img onerror>`. `StoreTest` executes a store file through PHP and asserts it emits nothing — the exact thing a web server would do if the `.htaccess` went missing. `HttpTest` boots a real server on a throwaway document root and drives it over HTTP: forged CSRF tokens, draft visibility, anonymous admin access, and an upload of a PHP file renamed `.png`.

Writing them found three bugs that hand-testing had missed, including one where every item saved without an explicit slug received the same meaningless URL.

## Configuration

There is no config file. Everything is set in **Settings** in the admin and stored in `data/settings.php`.

| Setting | Purpose | Note |
|---|---|---|
| Site name | Title tag and header | |
| Tagline | Shown under the site name | Optional |
| Site URL | Absolute URLs in the sitemap | No trailing slash. Without it, `sitemap.xml` and `robots.txt` emit relative URLs |
| Contact email | Shown in the footer | Form submissions go to Messages either way |
| Accent colour | Public site accent | Validated as a hex colour |

## Directory layout

Only `public/` is web-accessible. On shared hosting its *contents* become `public_html` and everything else sits one level above:

```
~/domains/example.com/
├── src/                  application code
├── views/                templates
├── data/                 the content store — never web-reachable
└── public_html/          the web root
    ├── index.php
    ├── .htaccess
    ├── assets/
    └── uploads/
```

`public/index.php` probes one directory up for `src/`, then two, so the same code runs from a local `php -S` in the repo root and from that layout with no edit.

Deployment is covered in [hostinger-php-deploy](https://github.com/Subramanyam2312/hostinger-php-deploy).

## Architecture

```
src/Store.php      the <?php exit; ?> JSON store — atomic writes, file locking, revisions
src/Auth.php       single account, bcrypt, session hardening, login throttle
src/Csrf.php       per-session token, constant-time comparison
src/Html.php       output escaping, allow-list sanitiser, URL scheme checks
src/Content.php    pages and posts, drafts, slug collision handling
src/Media.php      upload validation, EXIF stripping
src/App.php        wiring, template rendering, security headers
src/admin.php      admin routes
public/index.php   front controller
```

Zero runtime dependencies. No framework, no autoloader package, no `vendor/`.

Writes go through `Store::write()`, which writes to a temporary file and `rename()`s over the target — atomic within a filesystem, so a reader never sees a half-written document and a crash mid-write leaves the previous version intact. Read-modify-write goes through `Store::mutate()`, which holds an exclusive `flock` so two concurrent requests cannot silently discard each other's changes.

## Limitations

Read this section before choosing it.

- **Not built for concurrent editing.** File locking prevents corruption, but this is designed for one person editing one site. It is not a multi-user CMS.
- **Won't scale to thousands of items.** Every read parses the whole document. Fine for a brochure site or a blog with a few hundred posts; the wrong tool at ten thousand.
- **No full-text search.** No index, and adding one to a flat-file store is a real project.
- **No coverage measurement.** 84 tests cover the store, auth, sanitiser, content rules and the HTTP surface, but nothing measures what they miss. The admin templates in particular are only exercised incidentally.
- **No mailer.** Contact submissions are stored, not emailed. Adding SMTP means adding a dependency, which was the thing being avoided.
- **No password reset.** No mail, so no reset link. Recovery means deleting `data/admin.php` on the server, which reopens setup; content is untouched.
- **Templates escape their own output.** There is no auto-escaping layer, so a template that forgets `Html::escape()` is a live XSS hole. Read `views/` carefully before extending it.
- **The sanitiser handles authored content, not untrusted input.** It is an allow-list over `strip_tags` and it guards text typed by the one person holding the admin password. If you ever accept HTML from visitors, put HTMLPurifier in front of it instead.
- **Single account by design.** No roles, no permissions, no second user.
- **No i18n.** English-only admin interface.
- **Apache/LiteSpeed assumed.** The `.htaccess` files do real work here. On nginx you must translate them yourself — the `<?php exit; ?>` guard still protects the store, but the upload directory's execution rules and the front-controller rewrite need an nginx equivalent.

## License

MIT — see [LICENSE](LICENSE).

---

Built by [Subramanyam M N](https://subramanyammn.in).
