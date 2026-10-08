<?php
/**
 * Bonsai → Post Expiry: an overview of upcoming and recent expiries.
 *
 * The screen is registered with the shared Bonsai Hub (lib/bonsai-hub/),
 * which prints the header, plugin nav, tab bar and page shell. This class
 * prints the Overview tab; the Settings tab (admins only) is BPE_Settings.
 * Expiry dates themselves are set per post from the edit screen.
 *
 * @package BonsaiPostExpiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin overview.
 */
class BPE_Admin {

	const SLUG = 'bonsai-post-expiry';

	/**
	 * Editors as well as admins schedule content, so they can see the list.
	 */
	const CAPABILITY = 'edit_others_posts';

	/**
	 * Rows per table.
	 */
	const UPCOMING_LIMIT = 50;
	const EXPIRED_LIMIT  = 20;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'bonsai_hub_modules', array( __CLASS__, 'register_hub_module' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BPE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register the screen with the Bonsai menu.
	 *
	 * @param array $modules Modules registered so far.
	 * @return array
	 */
	public static function register_hub_module( $modules ) {
		$modules[ self::SLUG ] = array(
			'label'       => 'Post Expiry',
			'title'       => 'Bonsai Post Expiry',
			'description' => 'Posts with an expiry date switch to Draft when it passes. Set the date in the <strong>Post Expiry</strong> box on any edit screen.',
			'version'     => BPE_VERSION,
			'repo'        => BPE_Updater::REPO,
			'capability'  => self::CAPABILITY,
			'enqueue'     => array( __CLASS__, 'assets' ),
			'tabs'        => array(
				'overview' => array(
					'label'  => __( 'Overview', 'bonsai-post-expiry' ),
					'render' => array( __CLASS__, 'render' ),
				),
				'settings' => array(
					'label'      => __( 'Settings', 'bonsai-post-expiry' ),
					'capability' => BPE_Settings::CAPABILITY,
					'render'     => array( 'BPE_Settings', 'render' ),
				),
			),
		);

		return $modules;
	}

	/**
	 * "Overview" link on the plugin's row on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( class_exists( 'Bonsai_Hub' ) && current_user_can( self::CAPABILITY ) ) {
			array_unshift(
				$links,
				'<a href="' . esc_url( Bonsai_Hub::url( self::SLUG ) ) . '">' . esc_html__( 'Overview', 'bonsai-post-expiry' ) . '</a>'
			);
		}

		return $links;
	}

	/**
	 * Styles for the overview. Called by the hub on our screen only, after
	 * the shared Bonsai styles.
	 *
	 * @return void
	 */
	public static function assets() {
		wp_enqueue_style( 'bpe-admin', BPE_URL . 'assets/admin.css', array( 'bonsai-hub-ui' ), BPE_VERSION );
	}

	/**
	 * Print the overview: cron warnings, upcoming expiries, recently expired,
	 * then developer notes.
	 *
	 * @return void
	 */
	public static function render() {
		self::render_cron_notes();

		$upcoming = BPE_Expiry::upcoming( self::UPCOMING_LIMIT );
		$expired  = BPE_Expiry::recently_expired( self::EXPIRED_LIMIT );
		?>
		<section class="bonsai-ui-card" aria-labelledby="bpe-upcoming-title">
			<h2 class="bonsai-ui-card__title" id="bpe-upcoming-title"><?php esc_html_e( 'Upcoming expiries', 'bonsai-post-expiry' ); ?></h2>
			<p class="bonsai-ui-card__intro">
				<?php
				printf(
					/* translators: 1: number of posts, 2: site timezone */
					esc_html__( 'Soonest first, up to %1$d posts. Times are in the site timezone (%2$s).', 'bonsai-post-expiry' ),
					(int) self::UPCOMING_LIMIT,
					esc_html( wp_timezone_string() )
				);
				?>
			</p>

			<?php if ( $upcoming ) : ?>
				<table class="widefat striped bpe-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Title', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Expires', 'bonsai-post-expiry' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $upcoming as $post ) : ?>
							<?php $ts = (int) get_post_meta( $post->ID, BPE_Expiry::META, true ); ?>
							<tr>
								<td><?php self::render_title( $post ); ?></td>
								<td><?php echo esc_html( self::type_label( $post ) ); ?></td>
								<td><?php echo esc_html( self::status_label( $post ) ); ?></td>
								<td>
									<?php echo esc_html( wp_date( 'd/m/Y H:i', $ts ) ); ?>
									<br>
									<?php if ( $ts > time() ) : ?>
										<span class="description">
											<?php
											/* translators: %s: human-readable time difference, e.g. "3 days" */
											echo esc_html( sprintf( __( 'in %s', 'bonsai-post-expiry' ), human_time_diff( time(), $ts ) ) );
											?>
										</span>
									<?php else : ?>
										<span class="bonsai-ui-badge bonsai-ui-badge--warning"><?php esc_html_e( 'Due on next cron run', 'bonsai-post-expiry' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No posts have an expiry date set.', 'bonsai-post-expiry' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="bonsai-ui-card" aria-labelledby="bpe-expired-title">
			<h2 class="bonsai-ui-card__title" id="bpe-expired-title"><?php esc_html_e( 'Recently expired', 'bonsai-post-expiry' ); ?></h2>
			<p class="bonsai-ui-card__intro">
				<?php
				printf(
					/* translators: %d: number of posts */
					esc_html__( 'The last %d posts to expire. Setting a new expiry date on a post removes it from this list.', 'bonsai-post-expiry' ),
					(int) self::EXPIRED_LIMIT
				);
				?>
			</p>

			<?php if ( $expired ) : ?>
				<table class="widefat striped bpe-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Title', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status now', 'bonsai-post-expiry' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Expired', 'bonsai-post-expiry' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $expired as $post ) : ?>
							<tr>
								<td><?php self::render_title( $post ); ?></td>
								<td><?php echo esc_html( self::type_label( $post ) ); ?></td>
								<td><?php echo esc_html( self::status_label( $post ) ); ?></td>
								<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) get_post_meta( $post->ID, BPE_Expiry::META_DONE, true ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing has expired yet.', 'bonsai-post-expiry' ); ?></p>
			<?php endif; ?>
		</section>

		<div class="bonsai-ui-help">
			<p>
				<?php
				printf(
					/* translators: %s: default time, e.g. 23:59 */
					esc_html__( 'Published, scheduled and private posts switch to Draft at the chosen time. If no time is given it defaults to %s. Each expiry is a single WP-Cron event, with an hourly check as a safety net.', 'bonsai-post-expiry' ),
					'<code>' . esc_html( BPE_Expiry::DEFAULT_TIME ) . '</code>'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: filter name, 2: filter name, 3: action name */
					esc_html__( 'Developers: limit post types with the %1$s filter and required categories with %2$s. Hook into %3$s to send notifications or purge caches when a post expires.', 'bonsai-post-expiry' ),
					'<code>bpe_post_types</code>',
					'<code>bpe_required_categories</code>',
					'<code>bpe_post_expired</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Warnings for cron setups that would stop posts expiring on time.
	 *
	 * @return void
	 */
	private static function render_cron_notes() {
		$next_sweep = wp_next_scheduled( BPE_Expiry::SWEEP );

		if ( ! $next_sweep ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The hourly expiry check isn\'t scheduled. Deactivate and reactivate the plugin to restore it.', 'bonsai-post-expiry' ) . '</p></div>';
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<div class="notice notice-info inline"><p>';
			printf(
				/* translators: %s: wp-cron.php */
				esc_html__( 'WP-Cron is disabled on this site, so posts only expire when a server cron job calls %s. Check one is set up with the host.', 'bonsai-post-expiry' ),
				'<code>wp-cron.php</code>'
			);
			echo '</p></div>';
		}
	}

	/**
	 * Post title, linked to its edit screen when the user can edit it.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private static function render_title( $post ) {
		$title = get_the_title( $post );
		if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
			$title = __( '(no title)', 'bonsai-post-expiry' );
		}

		$link = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : '';

		if ( $link ) {
			echo '<a href="' . esc_url( $link ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
		} else {
			echo '<strong>' . esc_html( $title ) . '</strong>';
		}
	}

	/**
	 * Singular post type label, e.g. "Page".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function type_label( $post ) {
		$object = get_post_type_object( $post->post_type );
		return $object ? $object->labels->singular_name : $post->post_type;
	}

	/**
	 * Post status label, e.g. "Published".
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function status_label( $post ) {
		$object = get_post_status_object( $post->post_status );
		return $object ? $object->label : $post->post_status;
	}
}
