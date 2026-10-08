<?php
/**
 * Expiry: the edit-screen box, saving and scheduling, the cron callbacks
 * that switch posts to Draft, and the "Expires" list column.
 *
 * Meta keys, cron hook names and the bpe_ filters/actions are unchanged from
 * 1.0.0, so expiries scheduled on older versions keep working after updating.
 *
 * @package BonsaiPostExpiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post expiry.
 */
final class BPE_Expiry {

	const META         = '_bpe_expires';     // UTC unix timestamp.
	const META_DONE    = '_bpe_expired_at';  // When it was actually expired.
	const HOOK         = 'bpe_expire_post';  // Single event per post.
	const SWEEP        = 'bpe_sweep';        // Hourly safety net.
	const NONCE        = 'bpe_nonce';
	const VERSION_OPT  = 'bpe_version';      // Last version that ran maybe_upgrade().
	const DEFAULT_TIME = '23:59';            // "Midnight" of the chosen day. Change to '00:00' for start of day.

	/** Statuses that get knocked back to draft. */
	const EXPIRABLE = array( 'publish', 'future', 'private' );

	/**
	 * Hook in. Runs on every request, as the cron callbacks must be registered
	 * when wp-cron.php fires them.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'expire' ) );
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'unschedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_columns' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* -------------------------------------------------------------------------
	 * Post types
	 * ---------------------------------------------------------------------- */

	/**
	 * Post types that get the expiry box and column.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		/**
		 * Filter which post types get the expiry box.
		 * add_filter( 'bpe_post_types', fn() => [ 'post', 'event' ] );
		 */
		return apply_filters( 'bpe_post_types', array_values( $types ) );
	}

	/* -------------------------------------------------------------------------
	 * Assets
	 * ---------------------------------------------------------------------- */

	/**
	 * Edit screen gets the box script and styles; list screens get the styles
	 * for the "Expired" column label.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'bpe-admin', BPE_URL . 'assets/admin.css', array(), BPE_VERSION );

		if ( 'edit.php' === $hook ) {
			return;
		}

		wp_enqueue_script( 'bpe-admin', BPE_URL . 'assets/admin.js', array( 'jquery' ), BPE_VERSION, true );
		wp_localize_script(
			'bpe-admin',
			'BPE_EXPIRY',
			array(
				'defaultTime' => self::DEFAULT_TIME,
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Meta box
	 * ---------------------------------------------------------------------- */

	/**
	 * Register the sidebar box on every supported post type.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'bpe_expiry',
			__( 'Post Expiry', 'bonsai-post-expiry' ),
			array( __CLASS__, 'render' ),
			self::post_types(),
			'side',
			'high'
		);
	}

	/**
	 * Print the date/time fields. Behaviour (default time, clear button) is in
	 * assets/admin.js.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render( $post ) {
		$ts   = (int) get_post_meta( $post->ID, self::META, true );
		$done = (int) get_post_meta( $post->ID, self::META_DONE, true );
		$date = '';
		$time = '';

		if ( $ts ) {
			$dt   = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
			$date = $dt->format( 'Y-m-d' );
			$time = $dt->format( 'H:i' );
		}

		wp_nonce_field( 'bpe_save', self::NONCE );
		?>
		<div class="bpe-box">
			<p class="bpe-box__field">
				<label for="bpe_date"><strong><?php esc_html_e( 'Expiry date', 'bonsai-post-expiry' ); ?></strong></label>
				<input type="date" id="bpe_date" name="bpe_date" value="<?php echo esc_attr( $date ); ?>">
			</p>
			<p class="bpe-box__field">
				<label for="bpe_time"><strong><?php esc_html_e( 'Time', 'bonsai-post-expiry' ); ?></strong></label>
				<input type="time" id="bpe_time" name="bpe_time" value="<?php echo esc_attr( $time ); ?>">
			</p>
			<p>
				<button type="button" class="button-link" id="bpe_clear"><?php esc_html_e( 'Clear expiry', 'bonsai-post-expiry' ); ?></button>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: site timezone */
					esc_html__( 'Post switches to Draft when this passes. Time defaults to midnight if left blank. Timezone: %s', 'bonsai-post-expiry' ),
					esc_html( wp_timezone_string() )
				);
				?>
			</p>
			<?php if ( $done && ! $ts ) : ?>
				<p class="description bpe-expired">
					<?php
					printf(
						/* translators: %s: date expired */
						esc_html__( 'Expired on %s', 'bonsai-post-expiry' ),
						esc_html( wp_date( 'd/m/Y H:i', $done ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------------
	 * Save + scheduling
	 * ---------------------------------------------------------------------- */

	/**
	 * Save the expiry and (re)schedule its single cron event.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature set by save_post.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'bpe_save' ) ) {
			return; // Also bails for quick edit, REST-only saves and our own cron update.
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::unschedule( $post_id );

		$date = isset( $_POST['bpe_date'] ) ? sanitize_text_field( wp_unslash( $_POST['bpe_date'] ) ) : '';
		$time = isset( $_POST['bpe_time'] ) ? sanitize_text_field( wp_unslash( $_POST['bpe_time'] ) ) : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			$time = self::DEFAULT_TIME;
		}

		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		if ( ! $dt ) {
			delete_post_meta( $post_id, self::META );
			return;
		}

		$ts = $dt->getTimestamp();
		update_post_meta( $post_id, self::META, $ts );
		delete_post_meta( $post_id, self::META_DONE );

		// Past dates fire on the next cron run.
		wp_schedule_single_event( max( $ts, time() ), self::HOOK, array( (int) $post_id ) );
	}

	/**
	 * Remove a post's queued expiry event.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function unschedule( $post_id ) {
		wp_clear_scheduled_hook( self::HOOK, array( (int) $post_id ) );
	}

	/* -------------------------------------------------------------------------
	 * Expiry
	 * ---------------------------------------------------------------------- */

	/**
	 * Switch a post to Draft if its expiry has passed. Safe to call more than
	 * once: it re-checks the stored timestamp first.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function expire( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$ts = (int) get_post_meta( $post_id, self::META, true );
		if ( ! $ts || $ts > time() ) {
			return; // Cleared or moved since this event was queued.
		}

		if ( in_array( $post->post_status, self::EXPIRABLE, true ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);

			/** Hook for notifications, Make.com webhooks, cache purges etc. */
			do_action( 'bpe_post_expired', $post_id, $post );
		}

		delete_post_meta( $post_id, self::META );
		update_post_meta( $post_id, self::META_DONE, time() );
	}

	/**
	 * Hourly safety net for missed single events (cron hiccups, migrations, restores).
	 *
	 * @return void
	 */
	public static function sweep() {
		$ids = get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => self::EXPIRABLE,
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hourly cron only.
					array(
						'key'     => self::META,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			self::expire( $id );
		}
	}

	/* -------------------------------------------------------------------------
	 * Queries for the Bonsai → Post Expiry overview
	 * ---------------------------------------------------------------------- */

	/**
	 * Posts with an expiry set, soonest first.
	 *
	 * @param int $limit Max posts.
	 * @return WP_Post[]
	 */
	public static function upcoming( $limit = 50 ) {
		return self::query_by_meta( self::META, 'ASC', $limit );
	}

	/**
	 * Posts that have expired, most recent first.
	 *
	 * @param int $limit Max posts.
	 * @return WP_Post[]
	 */
	public static function recently_expired( $limit = 20 ) {
		return self::query_by_meta( self::META_DONE, 'DESC', $limit );
	}

	/**
	 * Posts of any supported type and status (bar trash) that have a meta key,
	 * ordered by its numeric value.
	 *
	 * @param string $key   Meta key.
	 * @param string $order ASC or DESC.
	 * @param int    $limit Max posts.
	 * @return WP_Post[]
	 */
	private static function query_by_meta( $key, $order, $limit ) {
		return get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'any',
				'posts_per_page'   => absint( $limit ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin overview only.
				'orderby'          => 'meta_value_num',
				'order'            => 'DESC' === $order ? 'DESC' : 'ASC',
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * Admin list column
	 * ---------------------------------------------------------------------- */

	/**
	 * Add the "Expires" column to every supported post type's list screen.
	 *
	 * @return void
	 */
	public static function register_columns() {
		foreach ( self::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	/**
	 * Column header.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function add_column( $cols ) {
		$cols['bpe_expiry'] = __( 'Expires', 'bonsai-post-expiry' );
		return $cols;
	}

	/**
	 * Column cell: expiry date, "Expired" date, or a dash.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function column( $col, $post_id ) {
		if ( 'bpe_expiry' !== $col ) {
			return;
		}
		$ts   = (int) get_post_meta( $post_id, self::META, true );
		$done = (int) get_post_meta( $post_id, self::META_DONE, true );

		if ( $ts ) {
			echo esc_html( wp_date( 'd/m/Y H:i', $ts ) );
		} elseif ( $done ) {
			echo '<span class="bpe-expired">' . esc_html__( 'Expired', 'bonsai-post-expiry' ) . ' ' . esc_html( wp_date( 'd/m/Y', $done ) ) . '</span>';
		} else {
			echo '&mdash;';
		}
	}

	/* -------------------------------------------------------------------------
	 * Activation and upgrades
	 * ---------------------------------------------------------------------- */

	/**
	 * Make sure the hourly sweep is queued.
	 *
	 * @return void
	 */
	public static function schedule_sweep() {
		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::schedule_sweep();
		update_option( self::VERSION_OPT, BPE_VERSION );
	}

	/**
	 * Updates from the Plugins screen don't re-run the activation hook, so
	 * re-check the sweep once per new version. One autoloaded option read
	 * otherwise.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( BPE_VERSION === get_option( self::VERSION_OPT ) ) {
			return;
		}

		self::schedule_sweep();
		update_option( self::VERSION_OPT, BPE_VERSION );
	}

	/**
	 * Deactivation hook: clear the sweep and every queued expiry. Expiry dates
	 * stay in post meta; on reactivation the sweep catches anything overdue.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::SWEEP );
		wp_unschedule_hook( self::HOOK );
		delete_option( self::VERSION_OPT );
	}
}
