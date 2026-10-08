<?php
/**
 * Required expiry: posts in categories ticked under Bonsai → Post Expiry →
 * Settings can't be published without an expiry date.
 *
 * The editors do the blocking (assets/admin.js for the classic editor,
 * assets/admin-block.js for the block editor). This class is the server-side
 * fallback for the classic editor, where the whole form arrives in one
 * request. The block editor saves the post over REST before it posts the meta
 * boxes, so the server can't see the date in time there.
 *
 * The fallback never unpublishes: a new publish is saved as Draft instead,
 * but a post that is already live only gets a warning.
 *
 * @package BonsaiPostExpiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Required expiry by category.
 */
final class BPE_Required {

	/**
	 * Set during a blocked classic save and passed to the redirect:
	 * 'draft' (saved as Draft instead) or 'live' (already live, warned only).
	 *
	 * @var string
	 */
	private static $blocked = '';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'enforce' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Whether a post type can have a required expiry: it gets the expiry box
	 * and uses categories.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function applies_to_type( $post_type ) {
		return in_array( $post_type, BPE_Expiry::post_types(), true ) && is_object_in_taxonomy( $post_type, 'category' );
	}

	/**
	 * Required categories for a post type: the ticked IDs, or none if the
	 * type doesn't use categories.
	 *
	 * @param string $post_type Post type name.
	 * @return int[]
	 */
	public static function categories_for_type( $post_type ) {
		return self::applies_to_type( $post_type ) ? BPE_Settings::required_categories() : array();
	}

	/**
	 * Names of the required categories, for messages, e.g. "Events, Courses".
	 *
	 * @return string
	 */
	public static function category_names() {
		$names = array();

		foreach ( BPE_Settings::required_categories() as $id ) {
			$term = get_term( $id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}

		return implode( ', ', $names );
	}

	/**
	 * The message shown when a date is missing. Shared by both editors.
	 *
	 * @return string
	 */
	public static function message() {
		return sprintf(
			/* translators: %s: category names, e.g. "Events, Courses" */
			__( 'Add an expiry date in the Post Expiry box before publishing. It\'s required for posts in: %s.', 'bonsai-post-expiry' ),
			self::category_names()
		);
	}

	/**
	 * Classic editor fallback: stop a new publish with no expiry date by
	 * saving it as Draft.
	 *
	 * @param array $data    Sanitised post data about to be saved.
	 * @param array $postarr Raw post data, including post_category.
	 * @return array
	 */
	public static function enforce( $data, $postarr ) {
		if ( ! in_array( $data['post_status'], BPE_Expiry::EXPIRABLE, true ) ) {
			return $data;
		}

		// Classic edit screen only. The block editor's meta box request
		// (?meta-box-loader) never changes status, and the JS covers it.
		if ( ! BPE_Expiry::box_submitted() || isset( $_GET['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; box_submitted() verifies our nonce.
			return $data;
		}

		// Only the post on the edit screen, not anything another plugin
		// inserts during the same request.
		$post_id = empty( $postarr['ID'] ) ? 0 : (int) $postarr['ID'];
		if ( ! $post_id || ! isset( $_POST['post_ID'] ) || absint( wp_unslash( $_POST['post_ID'] ) ) !== $post_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- box_submitted() verified our nonce above.
			return $data;
		}

		$required = self::categories_for_type( $data['post_type'] );
		if ( ! $required ) {
			return $data;
		}

		if ( ! array_intersect( self::posted_categories( $data['post_type'], $postarr ), $required ) ) {
			return $data;
		}

		if ( BPE_Expiry::posted_timestamp() ) {
			return $data;
		}

		// Already live or scheduled: don't take it down from here, that's
		// expiry's job. Save as normal and warn.
		if ( in_array( get_post_status( $post_id ), BPE_Expiry::EXPIRABLE, true ) ) {
			self::$blocked = 'live';
			return $data;
		}

		$data['post_status'] = 'draft';
		self::$blocked       = 'draft';

		// Core already fixed the date for the publish. Unless the user picked
		// a date, let it float again like a normal draft, so it takes the
		// real publish time later.
		if ( empty( $postarr['edit_date'] ) ) {
			$data['post_date_gmt'] = '0000-00-00 00:00:00';
		}

		return $data;
	}

	/**
	 * Category IDs from the submitted edit form. WordPress gives a post with
	 * no category the default one, so count that too.
	 *
	 * @param string $post_type Post type name.
	 * @param array  $postarr   Raw post data.
	 * @return int[]
	 */
	private static function posted_categories( $post_type, $postarr ) {
		$ids = isset( $postarr['post_category'] ) ? array_filter( array_map( 'absint', (array) $postarr['post_category'] ) ) : array();

		if ( ! $ids && 'post' === $post_type ) {
			$ids = array( (int) get_option( 'default_category' ) );
		}

		return array_values( $ids );
	}

	/**
	 * After a blocked classic save, flag it in the redirect so the edit
	 * screen can say why. Swaps core's "Post published." for "Post draft
	 * updated." when it was saved as Draft.
	 *
	 * @param string $location Redirect URL.
	 * @return string
	 */
	public static function redirect( $location ) {
		if ( '' === self::$blocked ) {
			return $location;
		}

		if ( 'draft' === self::$blocked ) {
			$location = add_query_arg( 'message', 10, $location );
		}

		return add_query_arg( 'bpe_required', self::$blocked, $location );
	}

	/**
	 * Notice on the classic edit screen after a blocked save.
	 *
	 * @return void
	 */
	public static function notice() {
		if ( ! isset( $_GET['bpe_required'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only.
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['bpe_required'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display flag only.

		if ( 'draft' === $state ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Saved as a draft, not published.', 'bonsai-post-expiry' ) . ' ' . esc_html( self::message() ) . '</p></div>';
		} elseif ( 'live' === $state ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This post is live without an expiry date.', 'bonsai-post-expiry' ) . ' ' . esc_html( self::message() ) . '</p></div>';
		}
	}
}
