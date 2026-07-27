# Building the SnowSEO plugin from source

The SnowSEO admin interface is a React application compiled with
[`@wordpress/scripts`](https://www.npmjs.com/package/@wordpress/scripts). The
human-readable source lives in `src/`, and the compiled output that the plugin
loads lives in `build/` (`index.js`, `index.css`, `index-rtl.css`,
`index.asset.php`).

Everything needed to reproduce `build/` from `src/` is in this repository:
https://github.com/Snow-SEO/snowseo-wordpress-plugin

## Requirements

* Node.js (a current LTS release) and npm.

## Build

From this plugin directory:

```sh
npm install
npm run build
```

`npm run build` runs `wp-scripts build`, which compiles `src/` into `build/`.
When it finishes, `build/` contains the exact assets distributed with the
plugin.

`npm run plugin-zip` produces the distributable archive. It packages only the
files a WordPress install needs at runtime: `snowseo.php`, `includes/`,
`uninstall.php`, `readme.txt`, `LICENSE.txt`, and the compiled `build/`. The
source (`src/`) and the build tooling stay here in the repository, which is the
maintained public source location referenced from `readme.txt`.

## Develop

```sh
npm install
npm start
```

`npm start` runs `wp-scripts start`, which rebuilds `build/` automatically on
every change to `src/`.

## Source layout

* `src/index.js`: React entry point that mounts the app on `#snowseo-root`.
* `src/App.js`: top-level component and routing between admin screens.
* `src/api.js`: wrapper around the plugin's WordPress REST endpoints.
* `src/pages/`: one file per admin screen (Dashboard, Articles, Settings, Logs, Help).
* `src/index.css`: styles for the admin interface.

The PHP that registers the admin page and the REST routes lives in `snowseo.php`
and `includes/class-snowseo-rest-api.php`.
