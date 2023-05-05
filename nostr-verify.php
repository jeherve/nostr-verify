<?php
/**
 * Plugin Name: Nostr Verify
 * Plugin URI: https://jeremy.hu/nostr-verify-wordpress-plugin/
 * Description: Verify yourself with Nostr, using NIP-05
 * Author: Jeremy Herve
 * Version: 1.2.0
 * Author URI: https://jeremy.hu/
 * License: GPL2+
 * Text Domain: nostr-verify
 * Requires at least: 6.2
 * Requires PHP: 7.2
 *
 * @package jeherve/NostrVerify
 */

namespace Jeherve\NostrVerify;

/**
 * Add rewrite rules
 */
function generate_rewrite_rules() {
	add_rewrite_rule( '^.well-known/nostr.json', 'index.php?well-known=nostr', 'top' );
}
add_action( 'init', __NAMESPACE__ . '\generate_rewrite_rules' );

/**
 * Flush rewrite rules
 */
function flush_rewrite_rules() {
	generate_rewrite_rules();
	\flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\flush_rewrite_rules' );
register_deactivation_hook( __FILE__, '\flush_rewrite_rules' );

/**
 * On plugin activation, redirect to the profile page so folks can enter their Nostr info.
 *
 * @param string $plugin        Path to the plugin file relative to the plugins directory.
 * @param bool   $network_wide  Whether to enable the plugin for all sites in the network.
 */
function redirect_to_settings( $plugin, $network_wide ) {
	// Bail if the plugin is not Nostr Verify.
	if ( plugin_basename( __FILE__ ) !== $plugin ) {
		return;
	}

	// Bail if we're on a multisite and the plugin is network activated.
	if ( $network_wide ) {
		return;
	}

	wp_safe_redirect( get_edit_profile_url() . '#nostr-verify' );
}
add_action( 'activated_plugin', __NAMESPACE__ . '\redirect_to_settings', 10, 2 );

/**
 * Add a link to the plugin's settings page
 * in the plugin's description in the admin.
 *
 * @param array $links Array of plugin action links.
 * @return array
 */
function add_settings_link( $links ) {
	$settings_link = sprintf(
		'<a href="%1$s#nostr-verify">%2$s</a>',
		esc_url( get_edit_profile_url() ),
		esc_html__( 'Settings', 'nostr-verify' )
	);
	array_unshift( $links, $settings_link );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), __NAMESPACE__ . '\add_settings_link' );

/**
 * Add query vars
 *
 * @param array $vars
 *
 * @return array
 */
add_action(
	'query_vars',
	function ( $vars ) {
		if ( ! in_array( 'well-known', $vars, true ) ) {
			$vars[] = 'well-known';
			$vars[] = 'name';
		}

		return $vars;
	}
);

/**
 * Parse the nostr request and render the document.
 *
 * @param WP $wp WordPress request context.
 */
function render_nostr_document( $wp ) {
	// check if it is a nostr request or not.
	if (
		! array_key_exists( 'well-known', $wp->query_vars )
		|| 'nostr' !== $wp->query_vars['well-known']
	) {
		return;
	}

	// Get all users with a Nostr name and public key.
	$users = get_users(
		array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- We need to query users with specific meta.
				'relation' => 'AND',
				array(
					'key'     => 'nostr_verify_name',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'nostr_verify_public_key',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	// If we do not have any data, bail and redirect to homepage.
	if ( empty( $users ) ) {
		wp_safe_redirect( home_url() );
		exit;
	}

	// Build the array of Nostr names and public keys.
	$names = array();
	foreach ( $users as $user ) {
		$names[ esc_attr( $user->nostr_verify_name ) ] = esc_attr( $user->nostr_verify_public_key );
	}

	// If the query var `name` is set, we only return the public key for that name.
	if (
		array_key_exists( 'name', $wp->query_vars )
		&& array_key_exists( esc_attr( $wp->query_vars['name'] ), $names )
	) {
		$names = array(
			esc_attr( $wp->query_vars['name'] ) => $names[ esc_attr( $wp->query_vars['name'] ) ],
		);
	}

	// Build the array of Nostr name(s) to be displayed.
	$info = array(
		'names' => $names,
	);

	header( 'Access-Control-Allow-Origin: *' );
	header( 'Content-Type: application/jrd+json; charset=' . get_bloginfo( 'charset' ), true );

	echo wp_json_encode( $info );

	// stop exactly here!
	exit;
}
add_action( 'parse_request', __NAMESPACE__ . '\render_nostr_document' );

/**
 * Add a section to user's profile to add their Nostr name and public key.
 *
 * @param WP_User $user User instance to output for.
 *
 * @return void
 */
function add_profile_section( $user ) {
	wp_nonce_field( 'nostr_verify_user_profile_update', 'nostr_verify_nonce' );

	printf(
		'<h2 id="nostr-verify">%1$s</h2>',
		esc_html__( 'Nostr', 'nostr-verify' )
	);

	?>
	<table class="form-table" role="presentation">
		<tbody>
			<tr class="user-name-wrap">
				<th>
					<label for="nostr-name"><?php esc_html_e( 'Nostr name', 'nostr-verify' ); ?></label>
				</th>
				<td>
					<input type="text" name="nostr-name" id="nostr-name" aria-describedby="email-description" value="<?php echo esc_attr( get_the_author_meta( 'nostr_verify_name', $user->ID ) ); ?>">
					<p class="description" id="nostr-name-description">
						<?php
						printf(
							wp_kses(
								/* translators: %s is a link to Nostr. */
								__( 'This will be the first part of your internet identifier (an email-like address) on Nostr. It could be <code>bob</code> for example. Your identifier on nostr (also known as "NIP-05") will then be <code>bob@%1$s</code>.<br/>If you’d like your identifier to be simply <code>@%1$s</code>, with no username, enter <code>_</code> in this field.', 'nostr-verify' ),
								array(
									'br'   => array(),
									'code' => array(),
								)
							),
							esc_attr( wp_parse_url( get_site_url(), PHP_URL_HOST ) )
						);
						?>
					</p>
				</td>
			</tr>

			<tr class="user-pubkey-wrap">
				<th>
					<label for="nostr-key"><?php esc_html_e( 'Public Key', 'nostr-verify' ); ?></label>
				</th>
				<td>
					<input type="text" name="nostr-key" id="nostr-key" value="<?php echo esc_attr( get_the_author_meta( 'nostr_verify_public_key', $user->ID ) ); ?>" class="regular-text code">
					<p class="description" id="nostr-key-description">
						<?php
						printf(
							wp_kses(
								/* translators: %s is a link to convert nostr keys. */
								__( 'You should have received a public key when you first created your account with Nostr. If your <strong>public key</strong> starts with <code>npub</code>, you will need to convert it to a hex key before you enter it here. To convert your key you can use <a href="%1$s" target="_blank" rel="noopener noreferrer">this tool</a>.', 'nostr-verify' ),
								array(
									'code'   => array(),
									'strong' => array(),
									'a'      => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							),
							esc_url( 'https://damus.io/key/' )
						);
						?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
}
add_action( 'show_user_profile', __NAMESPACE__ . '\add_profile_section' );
add_action( 'edit_user_profile', __NAMESPACE__ . '\add_profile_section' );

/**
 * Save the Nostr name and public key when the user profile is updated.
 *
 * @param int $user_id User ID.
 * @return void
 */
function save_nostr_profile_info( $user_id ) {
	if ( ! check_admin_referer( 'nostr_verify_user_profile_update', 'nostr_verify_nonce' ) ) {
		return;
	}

	$nostr_name = isset( $_POST['nostr-name'] ) ? sanitize_text_field( wp_unslash( $_POST['nostr-name'] ) ) : '';
	$nostr_key  = isset( $_POST['nostr-key'] ) ? sanitize_text_field( wp_unslash( $_POST['nostr-key'] ) ) : '';

	update_user_meta( $user_id, 'nostr_verify_name', $nostr_name );
	update_user_meta( $user_id, 'nostr_verify_public_key', $nostr_key );
}
add_action( 'personal_options_update', __NAMESPACE__ . '\save_nostr_profile_info' );
add_action( 'edit_user_profile_update', __NAMESPACE__ . '\save_nostr_profile_info' );
