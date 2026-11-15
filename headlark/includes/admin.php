<?php
// Settings → Headlark, the per-post "Regenerate SEO" action and the notice
// shown when another SEO plugin is active.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function headlark_admin_menu() {
	add_options_page( __( 'Headlark', 'headlark' ), __( 'Headlark', 'headlark' ), 'manage_options', 'headlark', 'headlark_settings_page' );
}
add_action( 'admin_menu', 'headlark_admin_menu' );

function headlark_settings_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'headlark' ), $args ), admin_url( 'options-general.php' ) );
}

// Last four characters only: the key is never sent back to the browser.
function headlark_masked_key() {
	$key = (string) get_option( 'headlark_key', '' );
	return '' === $key ? '' : '…' . substr( $key, -4 );
}

function headlark_sanitize_api( $value ) {
	$url  = esc_url_raw( trim( (string) $value ) );
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	$ok   = 0 === strpos( $url, 'https://' ) || ( 0 === strpos( $url, 'http://' ) && in_array( $host, array( 'localhost', '127.0.0.1' ), true ) );
	return $ok ? untrailingslashit( $url ) : 'https://headlark.com';
}

function headlark_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change Headlark settings.', 'headlark' ), 403 );
	}
	check_admin_referer( 'headlark_save' );
	$old      = headlark_settings();
	$types    = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
	$public   = get_post_types( array( 'public' => true ) );
	$settings = array(
		'api'        => headlark_sanitize_api( isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '' ),
		'enabled'    => ! empty( $_POST['enabled'] ),
		'post_types' => array_values( array_intersect( $types, array_keys( $public ) ) ),
		'seo_mode'   => isset( $_POST['seo_mode'] ) && 'off' === $_POST['seo_mode'] ? 'off' : 'gaps',
		'seo_ack'    => headlark_seo_plugin(),
	);
	update_option( 'headlark_settings', $settings );

	$key     = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
	$new_key = '' !== $key && get_option( 'headlark_key', '' ) !== $key;
	if ( $new_key ) {
		update_option( 'headlark_key', $key, false );
	}
	if ( get_option( 'headlark_key', '' ) && $settings['enabled'] && ( $new_key || $settings['post_types'] !== $old['post_types'] || $settings['api'] !== $old['api'] || ! $old['enabled'] ) ) {
		headlark_start_backfill();
		// The first batch right away, so the status table shows a result now.
		headlark_run_backfill( 15 );
	}
	wp_safe_redirect( headlark_settings_url( array( 'updated' => 1 ) ) );
	exit;
}
add_action( 'admin_post_headlark_save', 'headlark_save' );

function headlark_regenerate_all() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'headlark' ), 403 );
	}
	check_admin_referer( 'headlark_regenerate_all' );
	headlark_start_backfill();
	headlark_run_backfill( 15 );
	wp_safe_redirect( headlark_settings_url( array( 'updated' => 1 ) ) );
	exit;
}
add_action( 'admin_post_headlark_regenerate_all', 'headlark_regenerate_all' );

function headlark_backfill_text( $state ) {
	if ( $state && '' !== $state['error'] ) {
		/* translators: 1: number of posts, 2: error. */
		return sprintf( __( 'Stopped after %1$d posts: %2$s. Check the key and your plan, then regenerate.', 'headlark' ), $state['done'], $state['error'] );
	}
	if ( $state && ! empty( $state['finished'] ) ) {
		/* translators: %d: number of posts. */
		return sprintf( __( 'Done: %d published posts checked.', 'headlark' ), $state['done'] );
	}
	if ( $state ) {
		/* translators: %d: number of posts. */
		return sprintf( __( 'In progress: %d posts so far. Keep this page open to finish sooner.', 'headlark' ), $state['done'] );
	}
	return __( 'Saving a key goes through every published post once. Unchanged posts cost nothing.', 'headlark' );
}

// The open settings page runs the backfill too, so it doesn't wait on WP-Cron.
function headlark_backfill_step() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( null, 403 );
	}
	check_ajax_referer( 'headlark_backfill_step' );
	headlark_run_backfill( 15 );
	$state = headlark_backfill_state();
	wp_send_json_success( array( 'running' => headlark_backfill_running( $state ), 'text' => headlark_backfill_text( $state ) ) );
}
add_action( 'wp_ajax_headlark_backfill_step', 'headlark_backfill_step' );

function headlark_regenerate() {
	$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'headlark' ), 403 );
	}
	check_admin_referer( 'headlark_regenerate_' . $id );
	$r = headlark_optimize_post( $id );
	wp_safe_redirect( add_query_arg( 'headlark', $r['status'], wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php' ) ) );
	exit;
}
add_action( 'admin_post_headlark_regenerate', 'headlark_regenerate' );

function headlark_row_action( $actions, $post ) {
	if ( headlark_eligible( $post ) && current_user_can( 'edit_post', $post->ID ) ) {
		$url                   = wp_nonce_url( add_query_arg( array( 'action' => 'headlark_regenerate', 'post' => $post->ID ), admin_url( 'admin-post.php' ) ), 'headlark_regenerate_' . $post->ID );
		$actions['headlark'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Regenerate SEO', 'headlark' ) . '</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', 'headlark_row_action', 10, 2 );
add_filter( 'page_row_actions', 'headlark_row_action', 10, 2 );

function headlark_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$result = isset( $_GET['headlark'] ) ? sanitize_key( wp_unslash( $_GET['headlark'] ) ) : '';
	if ( $result ) {
		/* translators: %s: generated, cached, pending, skipped or error. */
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( sprintf( __( 'Headlark: %s.', 'headlark' ), $result ) ) . ' <a href="' . esc_url( headlark_settings_url() ) . '">' . esc_html__( 'Details', 'headlark' ) . '</a></p></div>';
	}
	$seo = headlark_seo_plugin();
	if ( $seo && headlark_settings()['seo_ack'] !== $seo ) {
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %1$s: name of the other SEO plugin. */
				__( 'Headlark found %1$s. Headlark now adds only the tags %1$s does not print (usually structured data, X cards and alt text), so nothing is duplicated. You can switch Headlark\'s head tags off instead.', 'headlark' ),
				$seo
			)
		) . ' <a href="' . esc_url( headlark_settings_url() ) . '">' . esc_html__( 'Choose in Settings → Headlark', 'headlark' ) . '</a></p></div>';
	}
}
add_action( 'admin_notices', 'headlark_admin_notices' );

function headlark_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = headlark_settings();
	$seo     = headlark_seo_plugin();
	$masked  = headlark_masked_key();
	$state   = headlark_backfill_state();
	$recent  = (array) get_option( 'headlark_recent', array() );
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$overdue = 0;
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		if ( $time < time() - 300 ) {
			$overdue += count( array_intersect_key( (array) $hooks, array( 'headlark_optimize' => 1, 'headlark_backfill' => 1 ) ) );
		}
	}
	$labels  = array(
		'generated' => __( 'Generated', 'headlark' ),
		'cached'    => __( 'Unchanged (free)', 'headlark' ),
		'pending'   => __( 'New SEO waiting for your review in the Headlark dashboard; the page keeps its approved SEO', 'headlark' ),
		'skipped'   => __( 'Skipped', 'headlark' ),
		'error'     => __( 'Error', 'headlark' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Headlark', 'headlark' ); ?></h1>
		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'headlark' ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php
			/* translators: %s: this site's domain. */
			echo esc_html( sprintf( __( 'Headlark writes each post\'s SEO once, when you publish or update it, and stores it with the post. Page views only read what is stored. In the Headlark dashboard, add %s to your site\'s domains and copy its secret key.', 'headlark' ), $host ) );
			?>
		</p>
		<?php if ( $overdue ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Waiting for WP-Cron: Headlark jobs are more than 5 minutes overdue. WP-Cron only runs when someone visits the site, or never if DISABLE_WP_CRON is set without a system cron. Set up a real cron job (see the Headlark docs), or press Regenerate on a post.', 'headlark' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="headlark_save">
			<?php wp_nonce_field( 'headlark_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="headlark-key"><?php esc_html_e( 'Secret key', 'headlark' ); ?></label></th>
					<td>
						<input name="key" id="headlark-key" type="password" class="regular-text" autocomplete="off" value="" placeholder="<?php echo esc_attr( $masked ? $masked : 'sk_…' ); ?>">
						<p class="description"><?php echo esc_html( $masked ? __( 'A key is saved. Leave empty to keep it.', 'headlark' ) : __( 'From the Headlark dashboard. Stored in this site\'s options.', 'headlark' ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled', 'headlark' ); ?></th>
					<td><label><input name="enabled" type="checkbox" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Generate on publish and print the tags', 'headlark' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'headlark' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'headlark' ); ?></legend>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
								<?php if ( 'attachment' === $type->name ) { continue; } ?>
								<label><input name="post_types[]" type="checkbox" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'With another SEO plugin', 'headlark' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'With another SEO plugin', 'headlark' ); ?></legend>
							<label><input name="seo_mode" type="radio" value="gaps" <?php checked( 'gaps', $s['seo_mode'] ); ?>> <?php esc_html_e( 'Add only the tags it does not print (recommended)', 'headlark' ); ?></label><br>
							<label><input name="seo_mode" type="radio" value="off" <?php checked( 'off', $s['seo_mode'] ); ?>> <?php esc_html_e( 'Print no head tags; only fill empty image alt text', 'headlark' ); ?></label>
							<p class="description">
								<?php
								/* translators: %s: name of the other SEO plugin. */
								echo esc_html( $seo ? sprintf( __( 'Active now: %s. Headlark never prints your title while it is active.', 'headlark' ), $seo ) : __( 'None active. Headlark sets the title, description, link previews and structured data.', 'headlark' ) );
								?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="headlark-api"><?php esc_html_e( 'API base', 'headlark' ); ?></label></th>
					<td><input name="api" id="headlark-api" type="url" class="regular-text" value="<?php echo esc_attr( $s['api'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Existing content', 'headlark' ); ?></h2>
		<p id="headlark-backfill"><?php echo esc_html( headlark_backfill_text( $state ) ); ?></p>
		<?php
		if ( headlark_backfill_running( $state ) && $s['enabled'] ) {
			$step = array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'headlark_backfill_step' ) );
			wp_print_inline_script_tag(
				'(function (o) {
					var el = document.getElementById("headlark-backfill");
					function step() {
						var body = new FormData();
						body.append("action", "headlark_backfill_step");
						body.append("_ajax_nonce", o.nonce);
						fetch(o.url, { method: "POST", body: body, credentials: "same-origin" })
							.then(function (r) { return r.json(); })
							.then(function (r) {
								el.textContent = r.data.text;
								if (r.data.running) { setTimeout(step, 2000); } else { location.reload(); }
							}, function () { setTimeout(step, 30000); });
					}
					step();
				})(' . wp_json_encode( $step ) . ');'
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="headlark_regenerate_all">
			<?php wp_nonce_field( 'headlark_regenerate_all' ); ?>
			<?php submit_button( __( 'Regenerate all', 'headlark' ), 'secondary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Recent results', 'headlark' ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th scope="col"><?php esc_html_e( 'Post', 'headlark' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'headlark' ); ?></th><th scope="col"><?php esc_html_e( 'When', 'headlark' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $recent ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Nothing yet.', 'headlark' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $recent as $r ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_permalink( $r['post'] ) ); ?>"><?php echo esc_html( get_the_title( $r['post'] ) ); ?></a></td>
					<td><?php echo esc_html( ( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ) . ( $r['message'] ? ': ' . $r['message'] : '' ) ); ?></td>
					<td>
						<?php
						/* translators: %s: time since, e.g. "5 mins". */
						echo esc_html( sprintf( __( '%s ago', 'headlark' ), human_time_diff( $r['time'] ) ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
