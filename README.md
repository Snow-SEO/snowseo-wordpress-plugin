# SnowSEO for WordPress

The official WordPress plugin for [SnowSEO](https://snowseo.com). It turns a WordPress
site into a receiver for the SnowSEO platform: articles you produce and approve in the
SnowSEO dashboard are pushed here as drafts, scheduled posts, private posts, or
published posts, together with their featured image, inline images, and SEO metadata.

This repository is the public, human-readable source for the plugin distributed on
WordPress.org. Every released version is tagged here (for example `v1.3.1`).

The plugin does not generate content on its own. All generation, editing, and approval
happen in your SnowSEO account, which is a paid service. The plugin itself is free and
licensed GPLv2 or later.

## Requirements

- WordPress 5.6 or newer (tested up to 7.0)
- PHP 7.4 or newer
- A SnowSEO account and a site token from your SnowSEO project settings

## Install

Install "SnowSEO" from the WordPress plugin directory, or upload a release ZIP through
**Plugins → Add New → Upload Plugin**. After activating, open **SnowSEO** in the admin
sidebar and paste your site token.

## Build from source

The admin screen is a React application compiled with
[`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts). The source
lives in `src/`; the compiled assets the plugin loads live in `build/`.

```sh
npm install
npm run build
```

`npm run build` compiles `src/` into `build/` (`index.js`, `index.css`,
`index-rtl.css`, `index.asset.php`), which is exactly what ships in the release ZIP.
See [BUILD.md](BUILD.md) for the development workflow and a description of the source
layout.

To produce the distributable archive:

```sh
npm run plugin-zip
```

## Repository layout

| Path                                  | Contents                                                     |
| ------------------------------------- | ------------------------------------------------------------ |
| `snowseo.php`                         | Plugin bootstrap, admin page, front-end SEO and JSON-LD output |
| `includes/class-snowseo-rest-api.php` | REST routes the SnowSEO backend and admin UI talk to          |
| `src/`                                | React source for the admin interface                          |
| `build/`                              | Compiled admin assets shipped with the plugin                 |
| `readme.txt`                          | WordPress.org plugin readme (changelog lives here)            |
| `uninstall.php`                       | Removes stored options and logs on delete                     |

## What the plugin talks to

The plugin communicates only with the SnowSEO API at `https://api.snowseo.com/v3`, and
only about site-level metadata (site URL, site title, WordPress version) plus your site
token. No visitor data is sent. The full inbound and outbound data disclosure is in
[readme.txt](readme.txt) under "External service: SnowSEO".

## Reporting a security issue

Please email hello@snowseo.com rather than opening a public issue.

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt).
