=== SnowSEO ===
Contributors: kapybara
Tags: seo, ai, content, publishing, ai-content
Requires at least: 5.7
Tested up to: 7.0
Stable tag: 1.3.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to SnowSEO for AI-assisted content publishing, scheduling, and analytics.

== Description ==

SnowSEO connects your WordPress site to the SnowSEO platform (https://snowseo.com), so AI-assisted articles you produce and approve in the SnowSEO dashboard can be drafted, scheduled, or published to this site automatically.

Once connected with a site token, you can:

* Push posts from the SnowSEO dashboard to WordPress as drafts, scheduled, private, or published posts.
* Sideload a featured image and caption with the post.
* Store SEO metadata (meta title/description, OG and Twitter tags, canonical URL) as post meta.
* See activity logs for everything the connection has done on this site.
* Check post status (published date and permalink) for posts originating from SnowSEO.

The plugin itself does not generate content. All article generation, editing, and approval happen in your SnowSEO account. This plugin acts as the WordPress receiver.

= Source code =

The complete human-readable source of this plugin is published at https://github.com/Snow-SEO/snowseo-wordpress-plugin under GPLv2 or later. That repository includes the uncompiled React source for the admin screen (`src/`), the PHP that ships with the plugin, and BUILD.md with the exact steps to reproduce the compiled `build/` assets distributed here (`npm install && npm run build`). Every release is tagged there, for example `v1.3.8`.

= External service: SnowSEO =

This plugin **requires** an account at SnowSEO (https://snowseo.com) and communicates with the SnowSEO API at `https://api.snowseo.com/v3` to function. Except for the public health check described below, communication begins only after a WordPress administrator connects the plugin with a SnowSEO site token. By connecting the plugin, you authorize the following data exchange:

**Data sent from your WordPress site to SnowSEO:**

* On connect (`/integrations/wordpress/validate-plugin-key`) - the plugin API key (site token) you paste into Settings, your site URL, site title, site tagline, WordPress version, plugin version, and a flag indicating that the connection should be saved.
* On connection status checks (same endpoint) - the same fields as above, used to re-validate the connection without creating a new one.
* On disconnect (`/integrations/wordpress/plugin-disconnect`) - the plugin API key as an authentication header only.
* On publish from the WordPress admin (`/cms/publish`) - the selected article slug, requested WordPress status, provider name, and plugin API key.
* On article listing or single-article fetch (`/cms/articles*`) - pagination and status filters or the selected article slug, plus the plugin API key. SnowSEO returns the requested SnowSEO article data for display in the WordPress admin.
* On settings fetch (`/integrations/wordpress/settings`) - the plugin API key only.
* On publishing status synchronization (`/wp-json/snowseo/v1/posts-status`, authenticated with the site token) - the site returns WordPress post IDs, statuses, publication dates, and permalinks. SnowSEO can request specific post IDs or, when no IDs are supplied, up to the 100 most recently modified posts.
* On a user-requested website audit autofix (`/wp-json/snowseo/v1/posts/by-url`, authenticated with the site token) - SnowSEO sends the selected public page URL, and the site returns the matching WordPress post or page ID, title, full content, status, and supported SnowSEO, Yoast, Rank Math, or SEOPress title, description, and canonical metadata. SnowSEO uses this data to prepare and apply the requested fix.
* On the public health check (`/wp-json/snowseo/v1/ping`) - the site returns whether the plugin is reachable and its installed version. This endpoint does not return site content or credentials.

**Data and commands received from SnowSEO:**

* On server publish (`/wp-json/snowseo/v1/receive-publish`, authenticated with your site token) - article title, HTML content (sanitized via `wp_kses`), excerpt, target status, scheduled date, SnowSEO article ID, featured-image URL and caption, and SEO meta fields. These are written as a standard WordPress post.
* On a user-requested website audit autofix (`/wp-json/snowseo/v1/posts/{id}/update`, authenticated with your site token) - an updated title, HTML content, or supported SEO metadata is written to the post or page selected for the fix.
* On a user-requested unpublish or delete action (`/wp-json/snowseo/v1/posts/{id}`, authenticated with your site token) - the requested post ID is sent to WordPress. The plugin permanently deletes the post only if it was originally created by SnowSEO.
* On site-token rotation (`/wp-json/snowseo/v1/invalidate`, authenticated with the previous site token) - SnowSEO can instruct the plugin to remove its stored site token and connection metadata. An optional SnowSEO team ID may be sent as a secondary connection check.
* On a user-requested PageSpeed fix (`/wp-json/snowseo/v1/perf/apply` and `/perf/revert`, authenticated with your site token) - SnowSEO sends only an identifier naming one fix from a fixed list. No file content is ever sent or accepted: everything written is fixed text built into this plugin. These endpoints do nothing at all unless an administrator has first enabled *Allow SnowSEO to apply performance fixes* under *SnowSEO > Performance* or *Settings > Reading*. Applying "text compression" adds a marker-delimited compression block to this site's `.htaccess`; applying "robots.txt" turns on a filter that removes invalid lines from the robots.txt WordPress generates. Both are reversible from the same screen, and both are removed when the plugin is uninstalled.
* On a capability check (`/wp-json/snowseo/v1/perf`, authenticated with your site token) - the site returns its web server type, whether `.htaccess` is writable, whether a robots.txt file exists on disk, and which caching or optimisation plugins are active. No site content or credentials are returned.
* Plugin updates are delivered through WordPress.org only; this plugin does not download updates from SnowSEO or any other external server.

The plugin does not send visitor analytics, IP addresses, cookies, or WordPress user account data to SnowSEO. A post or page selected for an audit autofix is sent with its full content and may therefore contain personal information that the site owner included in that content.

* SnowSEO Terms of Service: https://snowseo.com/terms-of-service
* SnowSEO Privacy Policy: https://snowseo.com/privacy-policy

You can disconnect at any time from the plugin Settings screen. Disconnecting removes the stored API key and connection metadata from this site and notifies the SnowSEO backend to deactivate the integration on its side. Uninstalling the plugin (Delete from the Plugins screen) also removes all stored options and activity logs.

== Installation ==

1. Upload the `snowseo` folder to the `/wp-content/plugins/` directory, or install through the WordPress Plugins screen.
2. Activate the plugin through the *Plugins* screen in WordPress.
3. Open *SnowSEO* in the admin sidebar.
4. Create or sign in to your account at https://snowseo.com, open your project, and copy the site token from your SnowSEO project settings.
5. Paste the site token into the plugin's Connection screen and click *Save & Connect*.

After connecting, all content publishing is driven from your SnowSEO dashboard. The plugin acts as the receiver and does not require further configuration.

== Frequently Asked Questions ==

= Do I need a SnowSEO account to use this plugin? =

Yes. This plugin is the WordPress receiver for the SnowSEO platform. Without an account at https://snowseo.com you have no way to obtain the site token required for the connection. The plugin will not function in isolation.

= Is SnowSEO a paid service? =

The WordPress plugin itself is free and GPL-licensed. The SnowSEO service it connects to is a paid subscription. A 7-day free trial is available on the Pro plan; other plans are billed from the start of the subscription. Current plans and pricing are listed at https://snowseo.com/pricing.

= Do I need a credit card to try SnowSEO? =

Yes. Signing up for any SnowSEO plan, including the 7-day free trial, requires a valid payment method. You are not charged until a trial ends and converts to a paid plan, and you can cancel during the trial to avoid being billed. The cancellation policy and pricing are at https://snowseo.com/pricing.

= What data does the plugin send to SnowSEO? =

See the *External service: SnowSEO* section above. In short, the plugin can send connection metadata, the site token, publishing request details, post status and permalink data, and the content and SEO metadata of a post or page selected for an audit autofix. It does not send visitor analytics, IP addresses, cookies, or WordPress user account data.

= How do I disconnect? =

Open *SnowSEO → Settings* in the WordPress admin and click *Disconnect*. The stored API key and connection metadata are deleted locally and the SnowSEO backend is notified to deactivate the integration.

= How are scheduled posts handled? =

When SnowSEO sends a post with status `scheduled` and a future date, the plugin creates the post with status `future` and the supplied `post_date`. WordPress will publish it automatically at the scheduled time using its built-in cron.

= Which user owns posts created by SnowSEO? =

When SnowSEO publishes a post via server-to-server call (authenticated with the site token rather than a WordPress login), the post is assigned to the first administrator on the site. If you want a specific user as the author of incoming posts, ensure that user is an administrator and has the lowest user ID among administrators.

== Changelog ==

= 1.3.8 =
* The small layout rule SnowSEO adds for images it has sized is now loaded through WordPress's own stylesheet system instead of being written straight into the page head. Your pages look exactly the same, and a theme can now switch the rule off like any other stylesheet.
* Structured data for SnowSEO posts is now printed with WordPress's own script tag helper, so anything on your site that adjusts script tags, such as a content security policy, applies to it too.
* Uninstalling now locates your site's `.htaccess` with WordPress's site root helper instead of assuming it sits next to WordPress. On sites where WordPress lives in a subdirectory, leftover SnowSEO blocks are now cleaned out of the right file, and if the site root cannot be determined nothing is touched at all.
* This version needs WordPress 5.7 or newer.

= 1.3.7 =
* Canonical URLs sent from SnowSEO are now checked as web addresses before they are saved. Anything that is not a plain http or https address is discarded instead of being stored on the post or handed to Yoast, Rank Math or SEOPress.
* Housekeeping pass over the rest of the plugin: file operations now go through WordPress's own helpers, and the web server name read from the environment is cleaned before it is used. Nothing the plugin does has changed.

= 1.3.6 =
* Image alt text can now be fixed on images that are not part of a post's content - featured images, and images placed by your theme or page builder. WordPress reads the alt text for those from the media library, so SnowSEO now writes it there instead of editing the post body.
* Only the alt text of the matched attachment is changed. The image file, its title, caption and description are left untouched.
* Added PageSpeed fixes SnowSEO can apply for you, under SnowSEO > Page Speed. Off by default: SnowSEO cannot change anything on your site until an administrator allows it on that screen, and it can only apply fixes from a fixed list built into this plugin - it can never send its own file contents or server directives.
* That screen is a permission screen rather than a control panel. Fixes are applied from your SnowSEO dashboard, where you are already looking at the report that asked for them; this screen decides what SnowSEO is allowed to change, and shows what it has changed.
* Text compression (gzip / brotli) can be switched on with one click on Apache and LiteSpeed. The block is written to your site's `.htaccess` between clearly marked comments, and can be removed from the same screen, by hand, or by uninstalling the plugin.
* If another caching or optimisation plugin already manages compression, SnowSEO says so and points you at that plugin's setting instead of fighting it. On nginx and IIS it tells you the change has to be made in your server config, and shows the exact directive.
* Added browser cache lifetimes. Images, fonts and media are cached for a year; stylesheets and scripts for thirty days, deliberately not longer, so a theme that does not version its files can never serve a stale stylesheet for a year.
* Added a font-display fix, so your text stays visible in a fallback font while web fonts download instead of leaving a blank space.
* Added early connections to the font and script hosts your site already uses. The list of hosts is built into this plugin and matched against what your site actually loads - SnowSEO cannot supply an address for your visitors' browsers to connect to.
* Added an optional faster-first-paint optimizer that stops scripts and icon fonts blocking the moment your page appears. It never touches jQuery, anything with inline code attached, or your theme's main stylesheet. Before it stays on, the plugin loads your own pages from the outside and compares them; if anything is missing or broken, it puts the change back immediately.
* Four ways to switch that optimizer off, including a `snowseo-perf-off` file in wp-content that works when you cannot reach wp-admin at all. If a fatal error ever happens while it is running, the plugin notices on the same request and serves the next one unoptimized.
* robots.txt repair removes lines that search engines cannot parse from the robots.txt WordPress generates. It never changes what your site allows or blocks, and it stands down entirely if a robots.txt file exists on disk, showing you the corrected text to paste instead.
* The Page Speed screen checks what your site actually serves rather than guessing, so it can tell you when `/robots.txt` is being blocked by a firewall or when compression is already handled upstream.
* Every fix also has a checkbox under Settings > Reading, so nothing here depends on the SnowSEO dashboard being reachable.
* Added a Manage link to the plugin's row on the Plugins screen.

= 1.3.5 =
* Posts pushed from SnowSEO now carry their categories and tags. Terms that don't exist on this site yet are created automatically. Previously these were sent but ignored, so posts landed uncategorised.

= 1.3.4 =
* Enqueued the admin layout styling through the compiled plugin stylesheet and moved the dashboard menu below core items following WordPress.org review feedback.

= 1.3.3 =
* Expanded the external-service disclosure to cover authenticated post status checks, website audit autofixes, deletion of SnowSEO-created posts, health checks, and site-token invalidation. No runtime behavior changed.

= 1.3.2 =
* The plugin now reports the site's tagline (Settings → General → Tagline) to SnowSEO when connecting, so it stays in sync instead of only being captured once at initial setup.

Earlier release history is available in the tagged source repository at https://github.com/Snow-SEO/snowseo-wordpress-plugin/tags.
