<?php
/**
 * Smoke test, NOT a test suite. Run inside a booted WordPress:
 *
 *   pnpm --filter @snowseo/wordpress-plugin wp:start
 *   pnpm --filter @snowseo/wordpress-plugin wp:verify
 *
 * Covers the things static analysis cannot: that every class actually loads,
 * that WordPress accepted the routes and every callback is callable, that the
 * kses filter unhooks by callback identity, that a publish keeps a vetted
 * iframe while dropping an untrusted one, that media alt resolves a resized
 * variant back to its original, that connect stores what it should, that a
 * hostile canonicalUrl never reaches post meta or the SEO-plugin mirror, and
 * that the front-end CSS and JSON-LD are emitted by WordPress's own style and
 * script tag builders rather than echoed into the head.
 *
 * Know its limits before trusting a green run:
 *  - No framework, so no isolation and no guaranteed teardown. A fatal midway
 *    leaves the fixture post/attachment and the connection options behind.
 *  - It writes to the real database. Dev boxes only, never a shared site.
 *  - It is a handful of happy paths. Nothing here covers /posts/by-url,
 *    /invalidate, log pagination, taxonomy resolution, uninstall, or any of the
 *    .htaccess perf work. /posts/{id}/update and the SEO-plugin mirror are
 *    touched only through the canonicalUrl sanitization checks.
 *  - A pass means "no contradiction found", not "correct".
 *
 * PHPUnit is the upgrade path if this ever needs to be real: wp-env already
 * provisions the test site on :8889 and core's PHPUnit suite.
 *
 * Not shipped: zip-plugin.py copies an allowlist and neither tests/ nor this
 * file is on it.
 */

// This writes posts, attachments and options to a live database. Refuse to run
// anywhere that calls itself production, and require the local-dev marker that
// wp-env sets, so a stray `wp eval-file` against a real site cannot wipe a
// working connection.
if (function_exists('wp_get_environment_type') && 'production' === wp_get_environment_type()) {
	echo "REFUSING: environment type is 'production'. This test mutates the database.\n";
	return;
}

// Counters live in $GLOBALS explicitly: `wp eval-file` executes this file inside
// a function scope, so plain top-level variables are NOT globals and `global`
// would bind to names that never exist - silently leaving every count at zero.
$GLOBALS['snowseo_pass']    = 0;
$GLOBALS['snowseo_fail']    = 0;
// Fixtures are registered here the moment they exist, and torn down by a
// shutdown handler rather than at the end of the script. A fatal half way
// through would otherwise leave the post, the attachment and a fake API key
// behind - which is exactly what happened the first time this was run.
$GLOBALS['snowseo_fixtures'] = array('posts' => array(), 'attachments' => array(), 'options' => array());

function t($label, $ok, $detail = '')
{
	if ($ok) {
		$GLOBALS['snowseo_pass']++;
		echo "  ok    $label\n";
	} else {
		$GLOBALS['snowseo_fail']++;
		echo "  FAIL  $label" . ($detail ? " -- $detail" : '') . "\n";
	}
}

/** Snapshot an option so teardown can put back exactly what was there before. */
function snowseo_snapshot_option($name)
{
	$GLOBALS['snowseo_fixtures']['options'][$name] = get_option($name, null);
}

register_shutdown_function(function () {
	$fx = $GLOBALS['snowseo_fixtures'];
	foreach ($fx['posts'] as $id) {
		if (get_post($id)) {
			wp_delete_post($id, true);
			echo "     [teardown] removed post $id\n";
		}
	}
	foreach ($fx['attachments'] as $id) {
		if (get_post($id)) {
			wp_delete_attachment($id, true);
			echo "     [teardown] removed attachment $id\n";
		}
	}
	// Restore, not delete: this box may legitimately be connected to SnowSEO,
	// and blowing the key away would silently disconnect a working dev site.
	foreach ($fx['options'] as $name => $original) {
		if (null === $original) {
			delete_option($name);
		} else {
			update_option($name, $original);
		}
	}
});

echo "\n1. classes load\n";
foreach (array(
	'SnowSEO_FS',
	'SnowSEO_Log',
	'SnowSEO_Rest_Auth',
	'SnowSEO_Media',
	'SnowSEO_Rest_Connection',
	'SnowSEO_Rest_Publish',
	'SnowSEO_Rest_API',
	'SnowSEO_Perf',
	'SnowSEO_Perf_Htaccess',
	'SnowSEO_Perf_Assets',
	'SnowSEO_Perf_Guard',
	'SnowSEO_Perf_Render',
	'SnowSEO_Perf_Robots',
) as $class) {
	t($class, class_exists($class));
}

echo "\n2. cross-class members resolve at runtime\n";
t('SnowSEO_Rest_API::api_url()', is_string(SnowSEO_Rest_API::api_url()));
t('SnowSEO_Rest_API::OPTION_API_KEY', defined('SnowSEO_Rest_API::OPTION_API_KEY') || SnowSEO_Rest_API::OPTION_API_KEY === 'snowseo_api_key');
t('SnowSEO_Log::OPTION', SnowSEO_Log::OPTION === 'snowseo_activity_logs');
t('SnowSEO_Rest_Auth::check_admin_permission()', is_bool(SnowSEO_Rest_Auth::check_admin_permission()));
t('SnowSEO_Rest_Connection::is_connected()', is_bool(SnowSEO_Rest_Connection::is_connected()));

echo "\n3. routes registered with WordPress\n";
do_action('rest_api_init');
$routes = array_keys(rest_get_server()->get_routes());
$ours   = array_values(array_filter($routes, function ($r) {
	return strpos($r, '/snowseo/v1') === 0;
}));
// The namespace root itself is registered by core, so subtract it.
$count = count(array_filter($ours, function ($r) {
	return $r !== '/snowseo/v1';
}));
echo "     found $count snowseo route(s)\n";
foreach ($ours as $r) {
	echo "       $r\n";
}
t('every route has a callable handler', (function () use ($ours) {
	$server = rest_get_server();
	$all    = $server->get_routes();
	foreach ($ours as $route) {
		foreach ($all[$route] as $handler) {
			if (empty($handler['callback']) || ! is_callable($handler['callback'])) {
				echo "       uncallable: $route\n";
				return false;
			}
			if (! empty($handler['permission_callback']) && ! is_callable($handler['permission_callback'])) {
				echo "       uncallable permission_callback: $route\n";
				return false;
			}
		}
	}
	return true;
})());

echo "\n4. kses filter hooks and unhooks by identity\n";
$publisher = new SnowSEO_Rest_Publish();
$cb        = array($publisher, 'allow_iframe_in_post_kses');
add_filter('wp_kses_allowed_html', $cb, 10, 2);
t('add_filter registers', has_filter('wp_kses_allowed_html', $cb) === 10);
remove_filter('wp_kses_allowed_html', $cb, 10);
t('remove_filter unhooks', has_filter('wp_kses_allowed_html', $cb) === false);

echo "\n5. publish keeps a trusted iframe, drops an untrusted one\n";
$html = '<p>hello</p>'
	. '<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe>'
	. '<iframe src="https://evil.example.com/x"></iframe>'
	. '<script>alert(1)</script>';

$request = new WP_REST_Request('POST', '/snowseo/v1/receive-publish');
$request->set_param('title', 'SnowSEO verify.php fixture');
$request->set_param('content', $publisher->sanitize_content_param($html));
$request->set_param('status', 'draft');

$response = $publisher->handle_receive_publish($request);
$data     = is_wp_error($response) ? null : $response->get_data();
// The handler answers { success, data: { postId, url, status } } - the id is
// nested, not top level. Accept either shape so a future flattening still works.
$payload  = isset($data['data']) && is_array($data['data']) ? $data['data'] : (array) $data;
$post_id  = isset($payload['postId']) ? (int) $payload['postId'] : 0;

if (is_wp_error($response)) {
	t('receive-publish succeeded', false, $response->get_error_message());
} else {
	t('receive-publish succeeded', $post_id > 0, wp_json_encode($data));
}

if ($post_id > 0) {
	$GLOBALS['snowseo_fixtures']['posts'][] = $post_id;
	$saved = get_post_field('post_content', $post_id);
	t('youtube iframe survived both kses passes', strpos($saved, 'youtube.com/embed/abc123') !== false);
	t('untrusted iframe removed', strpos($saved, 'evil.example.com') === false);
	t('script tag removed', stripos($saved, '<script') === false);
	t('filter left unhooked after the write', has_filter('wp_kses_allowed_html', $cb) === false);
}

echo "\n6. media-library alt text (/media/alt)\n";
// A real attachment row is enough - attachment_url_to_postid() resolves from
// the guid/_wp_attached_file, no actual image bytes required.
$upload   = wp_upload_dir();
$rel      = '2026/01/snowseo-verify.jpg';
$att_id   = wp_insert_attachment(array(
	'post_title'     => 'snowseo verify fixture',
	'post_mime_type' => 'image/jpeg',
	'post_status'    => 'inherit',
	'guid'           => trailingslashit($upload['baseurl']) . $rel,
), $rel);
update_post_meta($att_id, '_wp_attached_file', $rel);
$att_url = trailingslashit($upload['baseurl']) . $rel;

$GLOBALS['snowseo_fixtures']['attachments'][] = $att_id;
t('fixture attachment created', $att_id > 0);
t('attachment_id_from_url resolves exact url', SnowSEO_Media::attachment_id_from_url($att_url) === $att_id);
// The rendered page usually links a resized variant; the handler must strip the
// -WxH suffix and still find the original.
t(
	'attachment_id_from_url resolves resized variant',
	SnowSEO_Media::attachment_id_from_url(trailingslashit($upload['baseurl']) . '2026/01/snowseo-verify-1024x768.jpg') === $att_id
);

$alt_req = new WP_REST_Request('POST', '/snowseo/v1/media/alt');
$alt_req->set_param('items', array(
	array('url' => $att_url, 'alt' => 'a verified alt string'),
	array('url' => trailingslashit($upload['baseurl']) . '2026/01/does-not-exist.jpg', 'alt' => 'nope'),
));
$alt_res  = SnowSEO_Media::handle_update_media_alt($alt_req);
$alt_data = is_wp_error($alt_res) ? array() : $alt_res->get_data();

t('one attachment updated', isset($alt_data['updated']) && 1 === (int) $alt_data['updated'], wp_json_encode($alt_data));
t('alt text written to _wp_attachment_image_alt', get_post_meta($att_id, '_wp_attachment_image_alt', true) === 'a verified alt string');
t('unresolvable url reported, not fatal', isset($alt_data['results'][1]['success']) && false === $alt_data['results'][1]['success']);

echo "\n7. connect handshake (backend mocked)\n";
// Snapshot before touching them - this box may already be connected.
snowseo_snapshot_option(SnowSEO_Rest_API::OPTION_API_KEY);
snowseo_snapshot_option(SnowSEO_Rest_API::OPTION_CONNECTION);
snowseo_snapshot_option(SnowSEO_Log::OPTION);
$mock = function () {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode(array(
			'valid' => true,
			'data'  => array(
				'teamId'    => 'team_verify',
				'teamName'  => 'Verify Team',
				'siteTitle' => 'Verify Site',
				'siteUrl'   => 'http://localhost:8888',
			),
		)),
		'response' => array('code' => 200, 'message' => 'OK'),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter('pre_http_request', $mock, 10, 3);

$conn_req = new WP_REST_Request('POST', '/snowseo/v1/connect');
$conn_req->set_param('apiKey', 'verify-fixture-key');
$conn_res  = SnowSEO_Rest_Connection::handle_connect($conn_req);
$conn_data = $conn_res->get_data();

t('connect returned success', ! empty($conn_data['success']), wp_json_encode($conn_data));
t('api key stored', get_option(SnowSEO_Rest_API::OPTION_API_KEY) === 'verify-fixture-key');
$stored = get_option(SnowSEO_Rest_API::OPTION_CONNECTION, array());
t('connection row stored', ! empty($stored['connected']) && 'team_verify' === ($stored['team_id'] ?? ''));
t('is_connected() now true', true === SnowSEO_Rest_Connection::is_connected());

// verify_plugin_key compares the header against the key we just stored.
$key_req = new WP_REST_Request('GET', '/snowseo/v1/status');
$key_req->set_header('X-Plugin-Key', 'verify-fixture-key');
t('verify_plugin_key accepts the stored key', true === SnowSEO_Rest_Auth::verify_plugin_key($key_req));
$bad_req = new WP_REST_Request('GET', '/snowseo/v1/status');
$bad_req->set_header('X-Plugin-Key', 'wrong-key');
t('verify_plugin_key rejects a wrong key', false === SnowSEO_Rest_Auth::verify_plugin_key($bad_req));

$logs = get_option(SnowSEO_Log::OPTION, array());
t('connect wrote an activity log entry', ! empty($logs) && 'connected' === ($logs[0]['status'] ?? ''));

remove_filter('pre_http_request', $mock, 10);
// Options are restored by the shutdown handler from the snapshots above.
// The API key stays in place until teardown, which is what lets section 9
// dispatch authenticated requests through the real REST server below.

echo "\n8. front-end CSS and structured data are built by WordPress, not echoed\n";
// Both used to be printed straight into wp_head as raw tags. The CSS now rides a
// source-less style handle, and the JSON-LD goes through
// wp_print_inline_script_tag(). This section has to run before section 9 defines
// WPSEO_VERSION, since a live SEO plugin turns the JSON-LD off by design.
SnowSEO_Perf_Assets::enqueue_sized_image_rule();
$sized_handle = SnowSEO_Perf_Assets::SIZED_IMAGE_HANDLE;
$sized_style  = isset(wp_styles()->registered[$sized_handle]) ? wp_styles()->registered[$sized_handle] : null;

t('sized-image style handle is registered', null !== $sized_style);
// A false src is the point: there is no stylesheet file, so nothing to request.
t('registered without a source', $sized_style && false === $sized_style->src);
t('style handle is enqueued', wp_style_is($sized_handle, 'enqueued'));
$sized_inline = (array) wp_styles()->get_data($sized_handle, 'after');
t(
	'rule attached as inline style data',
	in_array(':where(img[data-snowseo-sized]){height:auto}', $sized_inline, true),
	wp_json_encode($sized_inline)
);

ob_start();
wp_styles()->do_item($sized_handle);
$sized_markup = trim(ob_get_clean());
t('WP_Styles prints the rule itself', false !== strpos($sized_markup, 'height:auto'), $sized_markup);
t('no stylesheet request is emitted', false === stripos($sized_markup, '<link'), $sized_markup);

wp_dequeue_style($sized_handle);
wp_deregister_style($sized_handle);

$jsonld_post = wp_insert_post(array(
	'post_title'   => 'SnowSEO verify.php JSON-LD fixture',
	'post_status'  => 'publish',
	'post_content' => 'fixture',
));
$GLOBALS['snowseo_fixtures']['posts'][] = $jsonld_post;
update_post_meta($jsonld_post, '_snowseo_article_id', 'verify-fixture-article');

// snowseo_output_jsonld() reads the main query, so stand one up around the
// fixture and put the original back straight after.
$jsonld_prev_query     = $GLOBALS['wp_query'];
$jsonld_prev_the_query = $GLOBALS['wp_the_query'];
$GLOBALS['wp_query']     = new WP_Query(array('p' => $jsonld_post, 'post_type' => 'post'));
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

ob_start();
snowseo_output_jsonld();
$jsonld = trim(ob_get_clean());

$GLOBALS['wp_query']     = $jsonld_prev_query;
$GLOBALS['wp_the_query'] = $jsonld_prev_the_query;

t('json-ld printed for a SnowSEO post', 0 === strpos($jsonld, '<script'), $jsonld);
t('carries the application/ld+json type', false !== strpos($jsonld, 'application/ld+json'), $jsonld);
$jsonld_body = trim((string) preg_replace('#^<script[^>]*>|</script>$#', '', $jsonld));
$jsonld_data = json_decode($jsonld_body, true);
t(
	'body is parseable BlogPosting JSON',
	is_array($jsonld_data) && 'BlogPosting' === (isset($jsonld_data['@type']) ? $jsonld_data['@type'] : ''),
	$jsonld_body
);

echo "\n9. canonicalUrl is validated as a URL, not just sanitized as text\n";
// sanitize_text_field() leaves a javascript: or data: value intact, and this one
// is rendered into <head> and mirrored into the Yoast / Rank Math / SEOPress
// canonical meta. Dispatching through rest_get_server() rather than calling the
// handler directly means the route's sanitize_callback is exercised too.
$canon_post = wp_insert_post(array(
	'post_title'   => 'SnowSEO verify.php canonical fixture',
	'post_status'  => 'draft',
	'post_content' => 'fixture',
));
$GLOBALS['snowseo_fixtures']['posts'][] = $canon_post;
t('canonical fixture post created', $canon_post > 0);

/** Send one canonicalUrl through /posts/<id>/update and report what got stored. */
$send_canonical = function ($value) use ($canon_post) {
	$req = new WP_REST_Request('POST', "/snowseo/v1/posts/{$canon_post}/update");
	$req->set_header('X-Plugin-Key', 'verify-fixture-key');
	$req->set_param('meta', array('canonicalUrl' => $value));
	$res = rest_get_server()->dispatch($req);
	return array(
		'status' => $res->get_status(),
		'stored' => (string) get_post_meta($canon_post, '_snowseo_meta_canonicalUrl', true),
	);
};

$good = $send_canonical('https://example.com/canonical');
t('valid https canonical is stored', 200 === $good['status'] && 'https://example.com/canonical' === $good['stored'], wp_json_encode($good));

// Each hostile value must leave the previously stored good canonical alone:
// dropping it is right, silently clearing it would be a regression too.
foreach (array(
	'javascript: alert'      => 'javascript:alert(document.cookie)',
	'uppercase JaVaScRiPt'   => 'JaVaScRiPt:alert(1)',
	'data: URI'              => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
	'non-http(s) protocol'   => 'ftp://example.com/x',
) as $label => $hostile) {
	$r = $send_canonical($hostile);
	t(
		"$label is rejected, stored canonical untouched",
		'https://example.com/canonical' === $r['stored'],
		wp_json_encode($r)
	);
}

// An explicit empty string still means "clear this field".
$cleared = $send_canonical('');
t('empty string clears the canonical', '' === $cleared['stored'], wp_json_encode($cleared));

// The mirror into a third-party SEO plugin is the path the review flagged.
// Yoast is not installed here, so stand its version constant up to take that
// branch. Safe because this is a one-shot `wp eval-file` process and nothing
// after this point reads it.
if (! defined('WPSEO_VERSION')) {
	define('WPSEO_VERSION', '0.0-verify');
}
$send_canonical('https://example.com/mirrored');
t('valid canonical reaches the Yoast mirror', 'https://example.com/mirrored' === (string) get_post_meta($canon_post, '_yoast_wpseo_canonical', true));
$send_canonical('javascript:alert(1)');
$mirrored = (string) get_post_meta($canon_post, '_yoast_wpseo_canonical', true);
t('javascript: never reaches the Yoast mirror', false === stripos($mirrored, 'javascript'), $mirrored);

echo "\n10. atomic writer, after the move to wp_is_writable() / wp_delete_file()\n";
// Everything here stays inside get_temp_dir(). The .htaccess install/remove
// round trip exercises the same writer but mutates the site root, so it is not
// run from this file - drive it by hand on a disposable site if that path
// changes.
$fs_dir = get_temp_dir() . 'snowseo-verify-fs';
wp_mkdir_p($fs_dir);
$fs_file = $fs_dir . '/target.txt';

t('write_atomic creates a file', SnowSEO_FS::write_atomic($fs_file, "first\n"));
t('contents are correct', "first\n" === file_get_contents($fs_file));
t('no temp file left behind', ! file_exists($fs_file . '.snowseo-tmp'));

chmod($fs_file, 0644);
t('write_atomic overwrites', SnowSEO_FS::write_atomic($fs_file, "second\n"));
t('new contents are correct', "second\n" === file_get_contents($fs_file));
t('original file mode survives the rename', 0644 === (fileperms($fs_file) & 0777), decoct(fileperms($fs_file) & 0777));

// wp_delete_file() replaced @unlink() on write_atomic's failure branch.
$fs_stray = $fs_dir . '/stray.tmp';
file_put_contents($fs_stray, 'x');
wp_delete_file($fs_stray);
t('wp_delete_file removes a temp file', ! file_exists($fs_stray));

// wp_is_writable() replaced is_writable() at every call site.
t('wp_is_writable agrees on a file', wp_is_writable($fs_file) === is_writable($fs_file));
t('wp_is_writable agrees on a directory', wp_is_writable($fs_dir) === is_writable($fs_dir));
t('wp_is_writable agrees on a missing path', wp_is_writable($fs_dir . '/nope') === is_writable($fs_dir . '/nope'));

wp_delete_file($fs_file);
@rmdir($fs_dir);

$failed = $GLOBALS['snowseo_fail'];
$passed = $GLOBALS['snowseo_pass'];
echo "\n" . (0 === $failed ? 'ALL PASS' : "FAILURES: $failed") . "  (passed: $passed)\n";

// Non-zero exit so this is usable as a gate, not just something to eyeball.
if ($failed > 0) {
	if (class_exists('WP_CLI')) {
		WP_CLI::halt(1);
	}
	exit(1);
}
echo "\n";
