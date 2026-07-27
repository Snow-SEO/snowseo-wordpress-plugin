=== SnowSEO ===
Contributors: kapybara
Tags: seo, ai, content, publishing, ai-content
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.3.2
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

The complete human-readable source of this plugin is published at https://github.com/Snow-SEO/snowseo-wordpress-plugin under GPLv2 or later. That repository includes the uncompiled React source for the admin screen (`src/`), the PHP that ships with the plugin, and BUILD.md with the exact steps to reproduce the compiled `build/` assets distributed here (`npm install && npm run build`). Every release is tagged there, for example `v1.3.1`.

= External service: SnowSEO =

This plugin **requires** an account at SnowSEO (https://snowseo.com) and communicates with the SnowSEO API at `https://api.snowseo.com/v3` to function. By installing and connecting this plugin, you authorize the following data exchange:

**Outbound** - data sent from your site to SnowSEO:

* On connect (`/integrations/wordpress/validate-plugin-key`) - the plugin API key (site token) you paste into Settings, your site URL (`home_url()`), your site title (`get_bloginfo('name')`), and your WordPress version (`get_bloginfo('version')`).
* On status check (same endpoint, periodic) - the same fields as above, used to re-validate the connection.
* On disconnect (`/integrations/wordpress/plugin-disconnect`) - the plugin API key as an authentication header only.
* On publish-from-dashboard (`/cms/publish`) - the article slug, the requested target status, and the plugin API key.
* On article listing/single fetch (`/cms/articles*`) - pagination and status filters, plus the plugin API key.
* On settings fetch (`/integrations/wordpress/settings`) - the plugin API key only.

**Inbound** - data received from SnowSEO and written into your WordPress database:

* On server publish (`/wp-json/snowseo/v1/receive-publish`, authenticated with your site token) - article title, HTML content (sanitized via `wp_kses`), excerpt, target status, scheduled date, SnowSEO article ID, featured-image URL and caption, and SEO meta fields. These are written as a standard WordPress post.
* Plugin updates are delivered through WordPress.org only; this plugin does not download updates from SnowSEO or any other external server.

No personally identifiable information about your visitors is sent to SnowSEO. Only site-level metadata (URL, title, WP version) and the plugin API key are transmitted.

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

See the *External service: SnowSEO* section above. In short: your site URL, site title, WordPress version, and the plugin API key. No visitor data is transmitted.

= How do I disconnect? =

Open *SnowSEO → Settings* in the WordPress admin and click *Disconnect*. The stored API key and connection metadata are deleted locally and the SnowSEO backend is notified to deactivate the integration.

= How are scheduled posts handled? =

When SnowSEO sends a post with status `scheduled` and a future date, the plugin creates the post with status `future` and the supplied `post_date`. WordPress will publish it automatically at the scheduled time using its built-in cron.

= Which user owns posts created by SnowSEO? =

When SnowSEO publishes a post via server-to-server call (authenticated with the site token rather than a WordPress login), the post is assigned to the first administrator on the site. If you want a specific user as the author of incoming posts, ensure that user is an administrator and has the lowest user ID among administrators.

== Changelog ==

= 1.3.2 =
* The plugin now reports the site's tagline (Settings → General → Tagline) to SnowSEO when connecting, so it stays in sync instead of only being captured once at initial setup.

= 1.3.1 =
* Security: the `/invalidate` endpoint now requires this site's token in the `X-Plugin-Key` header. It previously accepted a team ID alone, which is an identifier rather than a secret, so anyone who knew it could disconnect a site.
* Replaced `parse_url()` with `wp_parse_url()` for consistent behaviour across PHP versions.
* Removed the unused `Domain Path` header and the redundant `load_plugin_textdomain()` call. WordPress loads translations for directory-hosted plugins automatically.
* Prefixed a global variable and routed the head meta output through `wp_kses()` so the escaping path is unambiguous.
* Documented the public source repository in this readme: https://github.com/Snow-SEO/snowseo-wordpress-plugin

= 1.3.0 =
* Added endpoints that let a SnowSEO website audit fix issues on pages you already have: resolve a post from its URL and update its title, content, or SEO metadata.
* SnowSEO-managed SEO output now applies to any post SnowSEO has written meta title or description to, not only to posts it published.
* Added a `/ping` health check so SnowSEO can confirm requests reach the plugin before attempting to publish.

= 1.2.0 =
* SnowSEO-published posts now carry full SEO and social metadata. When a supported SEO plugin (Yoast, Rank Math, or SEOPress) is active, the generated meta title, description, Open Graph, Twitter, and canonical values are written into that plugin's own fields so they render through it and stay editable. When no SEO plugin is active, the plugin outputs the meta and social tags itself, including the document title.
* Added BlogPosting structured data (JSON-LD), built from the published post so its URL, dates, author, and image match the live page.
* YouTube and other video embeds are now preserved in article content instead of being stripped.
* Inline article images are now imported into the WordPress media library instead of hotlinking to external URLs. Re-publishing an article reuses the existing media instead of creating duplicates.
* Featured image import now handles URLs without a file extension and assigns the image to the post author.
* Restricted the post-deletion endpoint to posts originally created by SnowSEO.
* Bumped `Tested up to` to WordPress 7.0.

= 1.1.0 =
* Renamed plugin slug from `snowseo-wordpress-plugin` to `snowseo` to comply with WordPress.org plugin directory naming guidelines.
* Added `readme.txt` with full external-service disclosure (data sent to and received from SnowSEO API).
* Updated `Tested up to` to WordPress 6.7.
* Removed in-plugin update checker. Plugin updates are now delivered exclusively through WordPress.org's standard update mechanism (per WordPress.org plugin guideline #8).
* Added safety net to deactivate the legacy `snowseo-wordpress-plugin` folder if both versions are present on a site.
* Internal: renamed PHP constants (`SNOWSEO_VERSION`, `SNOWSEO_PLUGIN_DIR`, `SNOWSEO_PLUGIN_URL`, `SNOWSEO_PLUGIN_FILE`) and helper function prefixes from `snowseo_wordpress_plugin_*` to `snowseo_*`. Stored option keys for credentials (`snowseo_api_key`, `snowseo_connection`, `snowseo_activity_logs`) are unchanged, so existing connections survive the upgrade.

= 1.0.2 =
* Hardening and minor UI fixes.

= 1.0.1 =
* Minor fixes.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.1 =
Security fix: disconnecting this site now requires your site token instead of a team ID alone. Update as soon as you can. Your existing connection and settings are preserved.

= 1.2.0 =
Adds front-end SEO output (meta tags, Open Graph, Twitter Cards, and JSON-LD), preserves video embeds, and imports article images into your media library. Your existing connection and settings are preserved.

= 1.1.0 =
The plugin folder was renamed from `snowseo-wordpress-plugin` to `snowseo`. Your connection and logs are preserved. If both versions appear on the Plugins screen after updating, deactivate and delete the older `snowseo-wordpress-plugin` entry.
