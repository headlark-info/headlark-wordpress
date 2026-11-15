<?php
/**
 * Plugin Name:       Headlark
 * Plugin URI:        https://headlark.com/docs/wordpress.html
 * Description:       AI-written titles, meta descriptions, link previews, structured data and image alt text. Generated once when you publish, served from post meta.
 * Version:           0.1.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Headlark
 * Author URI:        https://headlark.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       headlark
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HEADLARK_VERSION', '0.1.2' );
define( 'HEADLARK_META', '_headlark' );
// Set while a post's new SEO waits for approval in the dashboard (review mode).
define( 'HEADLARK_PENDING', '_headlark_pending' );
// Posts per WP-Cron run of the backfill, one run a minute: 500 posts take about
// 50 minutes and stay far below the API's per-key limit (600 requests a minute).
define( 'HEADLARK_BATCH', 10 );
define( 'HEADLARK_BUDGET', 20 );

function headlark_settings() {
	$saved = get_option( 'headlark_settings', array() );
	return array_merge(
		array(
			'api'        => 'https://headlark.com',
			'enabled'    => true,
			'post_types' => array( 'post', 'page' ),
			'seo_mode'   => 'gaps',
			'seo_ack'    => '',
		),
		is_array( $saved ) ? $saved : array()
	);
}

// SEO plugins that print their own title, description, Open Graph and canonical.
function headlark_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return 'Yoast SEO';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return 'Rank Math';
	}
	if ( defined( 'AIOSEO_VERSION' ) ) {
		return 'All in One SEO';
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return 'SEOPress';
	}
	if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
		return 'The SEO Framework';
	}
	return '';
}

/* Generation: runs on publish, on "Regenerate" and in the backfill, never on a page view. */

// Our own fetch of a page gets it without Headlark's tags, so the API sees what
// the theme and other plugins print. The token keeps it to that fetch.
function headlark_raw_token() {
	return substr( wp_hash( 'headlark-raw' ), 0, 20 );
}

function headlark_is_raw() {
	if ( ! empty( $GLOBALS['headlark_raw'] ) ) {
		return true;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared against a secret token.
	return isset( $_GET['headlark_raw'] ) && is_string( $_GET['headlark_raw'] ) && hash_equals( headlark_raw_token(), wp_unslash( $_GET['headlark_raw'] ) );
}

function headlark_raw_headers() {
	if ( headlark_is_raw() ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}
}
add_action( 'template_redirect', 'headlark_raw_headers', 0 );

function headlark_eligible( $post ) {
	$s = headlark_settings();
	return $post
		&& $s['enabled']
		&& 'publish' === $post->post_status
		&& '' === $post->post_password
		&& in_array( $post->post_type, (array) $s['post_types'], true );
}

// Page caches and CDNs must not answer this fetch with a copy that already has
// our tags: a fresh query string and no-cache headers on the way in, and
// DONOTCACHEPAGE plus no-cache headers on the way out (headlark_raw_headers).
// A cached copy would still mostly work, since the API strips [data-headlark]
// tags and data-headlark-alt alts, but our title has no marker and would leak
// into the input. TLS is verified; if that fails, the post is rendered locally.
function headlark_page_html( $post, $url ) {
	$res = wp_remote_get(
		add_query_arg( array( 'headlark_raw' => headlark_raw_token(), 'headlark_t' => time() ), $url ),
		array(
			'timeout'     => 10,
			'redirection' => 2,
			'headers'     => array( 'cache-control' => 'no-cache', 'pragma' => 'no-cache' ),
		)
	);
	if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
		$html = wp_remote_retrieve_body( $res );
		if ( false !== stripos( $html, '<head' ) ) {
			return $html;
		}
	}
	return headlark_build_html( $post, $url );
}

// For hosts that block requests to themselves: the post rendered without the theme.
function headlark_build_html( $post, $url ) {
	$GLOBALS['headlark_raw'] = true;
	$previous                = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['post']         = $post;
	setup_postdata( $post );
	$title   = get_the_title( $post );
	$content = apply_filters( 'the_content', $post->post_content );
	$image   = get_the_post_thumbnail( $post, 'large' );
	$GLOBALS['post'] = $previous;
	wp_reset_postdata();
	$GLOBALS['headlark_raw'] = false;

	return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8">'
		. '<title>' . esc_html( wp_strip_all_tags( $title . ' – ' . get_bloginfo( 'name' ) ) ) . '</title>'
		. '<link rel="canonical" href="' . esc_url( $url ) . '"></head><body>'
		. '<header>' . esc_html( get_bloginfo( 'name' ) ) . '</header><main><h1>' . esc_html( wp_strip_all_tags( $title ) ) . '</h1>'
		. $image . $content . '</main></body></html>';
}

function headlark_attrs( $tag ) {
	preg_match_all( '/([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $tag, $m, PREG_SET_ORDER );
	$attrs = array();
	foreach ( $m as $a ) {
		$attrs[ strtolower( $a[1] ) ] = html_entity_decode( isset( $a[4] ) ? $a[4] : ( isset( $a[3] ) ? $a[3] : $a[2] ), ENT_QUOTES );
	}
	return $attrs;
}

// Whether the page's <head> already has a tag matching one of the plan's
// `replaces` selectors, as src/render.ts writes them: `meta[name="description" i]`,
// `meta[property="og:title" i]`, `link[rel="canonical"]`. Values are compared
// case-insensitively with or without the `i` flag, so a near-duplicate such as
// <meta name="Description"> also counts as printed.
function headlark_has_tag( $html, $selector ) {
	if ( ! preg_match( '/^(\w+)\[([\w:-]+)="([^"]+)"(?:\s+[iI])?\]$/', $selector, $sel ) ) {
		return false;
	}
	$end  = stripos( $html, '</head>' );
	$head = false === $end ? $html : substr( $html, 0, $end );
	preg_match_all( '/<' . $sel[1] . '\b[^>]*>/i', $head, $tags );
	foreach ( $tags[0] as $tag ) {
		$attrs = headlark_attrs( $tag );
		if ( isset( $attrs[ $sel[2] ] ) && strtolower( $attrs[ $sel[2] ] ) === strtolower( $sel[3] ) ) {
			return true;
		}
	}
	return false;
}

// The plan from the API, checked and reduced to what this page lacks. The page
// keeps anything it already prints (another SEO plugin's tags, a theme's
// description, WordPress's canonical), and robots is never touched.
function headlark_keep( $plan, $html ) {
	$tags = array();
	foreach ( isset( $plan['tags'] ) && is_array( $plan['tags'] ) ? $plan['tags'] : array() as $t ) {
		$tag   = isset( $t['tag'] ) ? $t['tag'] : '';
		$attrs = isset( $t['attrs'] ) && is_array( $t['attrs'] ) ? $t['attrs'] : array();
		if ( ! in_array( $tag, array( 'title', 'meta', 'link', 'script' ), true ) ) {
			continue;
		}
		$clean = array();
		foreach ( $attrs as $k => $v ) {
			if ( in_array( $k, array( 'name', 'property', 'content', 'rel', 'href', 'type' ), true ) && is_string( $v ) ) {
				$clean[ $k ] = $v;
			}
		}
		$text     = isset( $t['text'] ) && is_string( $t['text'] ) ? $t['text'] : null;
		$replaces = isset( $t['replaces'] ) && is_string( $t['replaces'] ) ? $t['replaces'] : '';
		if ( 'meta' === $tag && isset( $clean['name'] ) && 'robots' === $clean['name'] ) {
			continue;
		}
		if ( 'title' !== $tag && '' !== $replaces && headlark_has_tag( $html, $replaces ) ) {
			continue;
		}
		if ( ( 'title' === $tag || 'script' === $tag ) && null === $text ) {
			continue;
		}
		$tags[] = array( 'tag' => $tag, 'attrs' => $clean, 'text' => $text );
	}
	$alts = array();
	foreach ( isset( $plan['alts'] ) && is_array( $plan['alts'] ) ? $plan['alts'] : array() as $a ) {
		if ( isset( $a['src'], $a['alt'] ) && is_string( $a['src'] ) && is_string( $a['alt'] ) && '' !== trim( $a['alt'] ) ) {
			$alts[ $a['src'] ] = $a['alt'];
		}
	}
	return array( 'tags' => $tags, 'alts' => $alts );
}

function headlark_record( $post_id, $status, $message = '' ) {
	$recent = array_values(
		array_filter(
			(array) get_option( 'headlark_recent', array() ),
			function ( $r ) use ( $post_id ) {
				return isset( $r['post'] ) && $r['post'] !== $post_id;
			}
		)
	);
	array_unshift( $recent, array( 'post' => $post_id, 'status' => $status, 'message' => $message, 'time' => time() ) );
	update_option( 'headlark_recent', array_slice( $recent, 0, 20 ), false );
}

// Sends the page to Headlark's optimize endpoint (the build CLI's contract:
// POST {url, html} with the secret key) and stores the result in post meta.
// Returns ['status' => generated|cached|pending|skipped|error, 'code' => HTTP status, 'retry' => seconds].
function headlark_optimize_post( $post_id ) {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );
	if ( ! headlark_eligible( $post ) ) {
		return array( 'status' => 'skipped', 'code' => 0 );
	}
	$key = (string) get_option( 'headlark_key', '' );
	if ( '' === $key ) {
		headlark_record( $post_id, 'error', __( 'No site key yet.', 'headlark' ) );
		return array( 'status' => 'error', 'code' => 401 );
	}
	$url = get_permalink( $post );
	if ( ! $url || wp_parse_url( $url, PHP_URL_QUERY ) ) {
		headlark_record( $post_id, 'skipped', __( 'Plain permalinks (?p=123) are not supported. Choose another structure in Settings → Permalinks.', 'headlark' ) );
		return array( 'status' => 'skipped', 'code' => 0 );
	}
	$html = headlark_page_html( $post, $url );
	$res  = wp_remote_post(
		untrailingslashit( headlark_settings()['api'] ) . '/api/v1/optimize',
		array(
			'timeout' => 90,
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $key,
			),
			'body'    => wp_json_encode( array( 'url' => $url, 'html' => $html ) ),
		)
	);
	if ( is_wp_error( $res ) ) {
		headlark_record( $post_id, 'error', $res->get_error_message() );
		return array( 'status' => 'error', 'code' => 0 );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['plan'] ) ) {
		$error = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] ) ? $body['error'] : 'HTTP ' . $code;
		headlark_record( $post_id, 'error', $error );
		return array( 'status' => 'error', 'code' => $code, 'retry' => (int) wp_remote_retrieve_header( $res, 'retry-after' ) );
	}
	$kept = headlark_keep( $body['plan'], $html );
	update_post_meta( $post_id, HEADLARK_META, array_merge( $kept, array( 'url' => $url, 'time' => time() ) ) );
	headlark_purge( $post_id );
	$status = ! empty( $body['pending'] ) ? 'pending' : ( empty( $body['cached'] ) ? 'generated' : 'cached' );
	if ( 'pending' === $status ) {
		update_post_meta( $post_id, HEADLARK_PENDING, 1 );
		if ( ! wp_next_scheduled( 'headlark_recheck' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'headlark_recheck' );
		}
	} else {
		delete_post_meta( $post_id, HEADLARK_PENDING );
	}
	headlark_record( $post_id, $status );
	return array( 'status' => $status, 'code' => 200 );
}
add_action( 'headlark_optimize', 'headlark_optimize_post' );

// Review mode: an approval in the dashboard reaches the post on the next
// hourly check. An unchanged page is a free cache hit; the schedule ends when
// nothing waits.
function headlark_recheck() {
	$ids = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'meta_key'       => HEADLARK_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'         => 'ids',
			'posts_per_page' => 20,
			'orderby'        => 'modified',
			'order'          => 'ASC',
		)
	);
	if ( ! $ids ) {
		wp_clear_scheduled_hook( 'headlark_recheck' );
		return;
	}
	foreach ( $ids as $id ) {
		$r = headlark_optimize_post( (int) $id );
		if ( 'skipped' === $r['status'] ) {
			delete_post_meta( (int) $id, HEADLARK_PENDING );
		}
		if ( in_array( $r['code'], array( 401, 402, 403, 429 ), true ) ) {
			return;
		}
	}
}
add_action( 'headlark_recheck', 'headlark_recheck' );

// Page caches would keep serving the page as it was before the new tags.
function headlark_purge( $post_id ) {
	clean_post_cache( $post_id );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $post_id );
	}
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $post_id );
	}
	do_action( 'litespeed_purge_post', $post_id );
	if ( isset( $GLOBALS['cloudflareHooks'] ) && is_object( $GLOBALS['cloudflareHooks'] ) && method_exists( $GLOBALS['cloudflareHooks'], 'purgeCacheByRelevantURLs' ) ) {
		$GLOBALS['cloudflareHooks']->purgeCacheByRelevantURLs( $post_id );
	}
	do_action( 'headlark_seo_updated', $post_id );
}

function headlark_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! headlark_eligible( $post ) || '' === get_option( 'headlark_key', '' ) ) {
		return;
	}
	// After the save request, so publishing in the editor never waits on us.
	// WordPress drops a duplicate of a still-pending event.
	wp_schedule_single_event( time(), 'headlark_optimize', array( (int) $post->ID ) );
	// Without this the event waits for the next visit. spawn_cron() is a
	// non-blocking request to wp-cron.php.
	if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
}
add_action( 'transition_post_status', 'headlark_on_transition', 10, 3 );

/* Backfill: existing published content, a batch per run. Runs come from WP-Cron
   and, while Settings → Headlark is open, from the page itself, so it finishes
   on sites where WP-Cron is slow or never runs (WordPress Playground). */

function headlark_start_backfill() {
	update_option( 'headlark_backfill', array( 'run' => wp_generate_password( 12, false ), 'cursor' => 0, 'done' => 0, 'error' => '', 'wait' => 0 ), false );
	wp_clear_scheduled_hook( 'headlark_backfill' );
	wp_schedule_single_event( time(), 'headlark_backfill' );
}

// Read past the in-memory option cache: another request may have moved it on.
function headlark_backfill_state() {
	wp_cache_delete( 'headlark_backfill', 'options' );
	$state = get_option( 'headlark_backfill' );
	return is_array( $state ) ? array_merge( array( 'run' => '', 'cursor' => 0, 'done' => 0, 'error' => '', 'wait' => 0 ), $state ) : null;
}

function headlark_backfill_running( $state ) {
	return $state && empty( $state['finished'] ) && '' === $state['error'];
}

// One run at a time, so WP-Cron and the settings page never send a post twice.
// A run that died holding the lock frees it after 5 minutes.
function headlark_lock() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = 'headlark_lock' AND option_value < %d", time() - 300 ) );
	return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('headlark_lock', %d, 'no')", time() ) );
}

function headlark_unlock() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'headlark_lock'" );
}

function headlark_run_backfill( $budget = HEADLARK_BUDGET ) {
	$state = headlark_backfill_state();
	if ( ! headlark_backfill_running( $state ) || ! headlark_settings()['enabled'] ) {
		return;
	}
	if ( time() < $state['wait'] || ! headlark_lock() ) {
		wp_schedule_single_event( max( time() + 60, $state['wait'] ), 'headlark_backfill' );
		return;
	}
	try {
		$state = headlark_backfill_state();
		if ( headlark_backfill_running( $state ) ) {
			$run   = $state['run'];
			$state = headlark_backfill_batch( $state, $budget );
			$now   = headlark_backfill_state();
			// "Regenerate all" during this batch started over; keep its state.
			if ( $now && $now['run'] === $run ) {
				update_option( 'headlark_backfill', $state, false );
			}
		}
	} finally {
		headlark_unlock();
	}
}
add_action( 'headlark_backfill', 'headlark_run_backfill', 10, 0 );

function headlark_backfill_batch( $state, $budget ) {
	global $wpdb;
	$types = array_values( (array) headlark_settings()['post_types'] );
	if ( ! $types ) {
		$state['finished'] = time();
		return $state;
	}
	$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$ids = $wpdb->get_col(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status = 'publish' AND post_password = '' AND post_type IN ($in) ORDER BY ID ASC LIMIT %d", array_merge( array( $state['cursor'] ), $types, array( HEADLARK_BATCH ) ) )
	);
	$start = time();
	foreach ( $ids as $i => $id ) {
		if ( $i > 0 && time() - $start >= $budget ) {
			wp_schedule_single_event( time() + 60, 'headlark_backfill' );
			return $state;
		}
		$r = headlark_optimize_post( (int) $id );
		if ( 429 === $r['code'] ) {
			$state['wait'] = time() + max( 60, $r['retry'] );
			wp_schedule_single_event( $state['wait'], 'headlark_backfill' );
			return $state;
		}
		if ( in_array( $r['code'], array( 401, 402, 403 ), true ) ) {
			$state['error'] = 402 === $r['code']
				? __( 'plan limit reached; the remaining posts, unchanged ones included, were not checked', 'headlark' )
				: ( 401 === $r['code'] ? __( 'the secret key was not accepted', 'headlark' ) : __( 'this site\'s domain is not on the Headlark site', 'headlark' ) );
			return $state;
		}
		$state['cursor'] = (int) $id;
		++$state['done'];
	}
	if ( count( $ids ) < HEADLARK_BATCH ) {
		$state['finished'] = time();
	} else {
		wp_schedule_single_event( time() + 60, 'headlark_backfill' );
	}
	return $state;
}

// Another SEO plugin changes what each page already prints, so re-check every page.
function headlark_on_plugin_change( $plugin ) {
	if ( preg_match( '#^(wordpress-seo|seo-by-rank-math|all-in-one-seo-pack|wp-seopress|autodescription)/#', $plugin ) && get_option( 'headlark_key', '' ) ) {
		headlark_start_backfill();
	}
}
add_action( 'activated_plugin', 'headlark_on_plugin_change' );
add_action( 'deactivated_plugin', 'headlark_on_plugin_change' );

function headlark_activate() {
	if ( get_option( 'headlark_key', '' ) ) {
		headlark_start_backfill();
	}
}
register_activation_hook( __FILE__, 'headlark_activate' );

function headlark_deactivate() {
	wp_clear_scheduled_hook( 'headlark_backfill' );
	wp_clear_scheduled_hook( 'headlark_recheck' );
	wp_unschedule_hook( 'headlark_optimize' );
}
register_deactivation_hook( __FILE__, 'headlark_deactivate' );

/* Page views: read post meta and print it. No request, no generation. */

function headlark_current() {
	if ( headlark_is_raw() || ! is_singular() || ! headlark_settings()['enabled'] ) {
		return null;
	}
	$id = get_queried_object_id();
	if ( ! in_array( get_post_type( $id ), (array) headlark_settings()['post_types'], true ) ) {
		return null;
	}
	$data = get_post_meta( $id, HEADLARK_META, true );
	return is_array( $data ) && isset( $data['tags'] ) ? $data : null;
}

// While another SEO plugin is active, `off` leaves the head to it entirely;
// `gaps` adds only the tags it didn't print when the page was last generated.
function headlark_head_tags() {
	$data = headlark_current();
	if ( ! $data ) {
		return array();
	}
	$seo = headlark_seo_plugin();
	if ( $seo && 'off' === headlark_settings()['seo_mode'] ) {
		return array();
	}
	return array_values(
		array_filter(
			$data['tags'],
			function ( $t ) use ( $seo ) {
				return ! ( $seo && 'title' === $t['tag'] );
			}
		)
	);
}

// WordPress prints a pre_get_document_title value as is, without escaping it.
function headlark_document_title( $title ) {
	foreach ( headlark_head_tags() as $t ) {
		if ( 'title' === $t['tag'] ) {
			return esc_html( $t['text'] );
		}
	}
	return $title;
}
add_filter( 'pre_get_document_title', 'headlark_document_title', 20 );

function headlark_tag_html( $t ) {
	if ( 'script' === $t['tag'] ) {
		$data = json_decode( $t['text'], true );
		return is_array( $data ) ? '<script type="application/ld+json" data-headlark>' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n" : '';
	}
	if ( 'meta' !== $t['tag'] && 'link' !== $t['tag'] ) {
		return '';
	}
	$out = '<' . $t['tag'];
	foreach ( $t['attrs'] as $k => $v ) {
		$out .= ' ' . $k . '="' . ( 'href' === $k ? esc_url( $v ) : esc_attr( $v ) ) . '"';
	}
	return $out . " data-headlark>\n";
}

function headlark_head() {
	foreach ( headlark_head_tags() as $t ) {
		echo headlark_tag_html( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in headlark_tag_html.
	}
}
add_action( 'wp_head', 'headlark_head', 1 );

/* Alt text: fills empty alts only, never replaces one the author wrote. */

function headlark_alt( $src ) {
	$data = headlark_current();
	return $data && isset( $data['alts'][ $src ] ) ? $data['alts'][ $src ] : '';
}

function headlark_attachment_alt( $attr ) {
	if ( isset( $attr['src'] ) && ( ! isset( $attr['alt'] ) || '' === trim( $attr['alt'] ) ) ) {
		$alt = headlark_alt( $attr['src'] );
		if ( '' !== $alt ) {
			$attr['alt']               = $alt;
			$attr['data-headlark-alt'] = '';
		}
	}
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'headlark_attachment_alt' );

function headlark_content_alts( $content ) {
	$data = headlark_current();
	if ( ! $data || empty( $data['alts'] ) ) {
		return $content;
	}
	return preg_replace_callback(
		'/<img\b[^>]*>/i',
		function ( $m ) {
			$attrs = headlark_attrs( $m[0] );
			if ( ! isset( $attrs['src'] ) || ( isset( $attrs['alt'] ) && '' !== trim( $attrs['alt'] ) ) ) {
				return $m[0];
			}
			$alt = headlark_alt( $attrs['src'] );
			if ( '' === $alt ) {
				return $m[0];
			}
			$tag = preg_replace( '/\salt\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $m[0] );
			return preg_replace( '/\s*\/?>$/', ' alt="' . esc_attr( $alt ) . '" data-headlark-alt$0', $tag, 1 );
		},
		$content
	);
}
add_filter( 'the_content', 'headlark_content_alts', 20 );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}
