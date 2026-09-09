<?php
/**
 * Plugin Name:       Feature Unique
 * Description:       Flags and reports duplicate use of images as featured images across posts. Warns in the Featured Image box and picker modal, adds columns to the Posts and Media Library list tables, and provides a Tools report page.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Brad Salomons
 * License:           GPL v2 or later
 * Text Domain:       feature-unique
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feature_Unique {

	/**
	 * Post type this plugin checks. Kept to 'post' per user's scope decision.
	 */
	const POST_TYPE = 'post';

	/**
	 * Memoized map of attachment_id => array of post rows using it as a featured image.
	 * Built once per request; not persisted, so it's always fresh.
	 *
	 * @var array|null
	 */
	private static $usage_map = null;

	public static function init() {
		// Classic editor: the "Featured Image" metabox is server-rendered, so this filter works there.
		add_filter( 'admin_post_thumbnail_html', array( __CLASS__, 'filter_featured_image_box' ), 10, 3 );

		// Block editor: the Featured Image panel is a React component and never calls the filter
		// above, so it needs its own JS-side warning via the editor.PostFeaturedImage filter.
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );

		// Priority PHP_INT_MAX so our column survives if another plugin/theme rebuilds
		// the columns array (rather than appending to it) on the same filter.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_list_column' ), PHP_INT_MAX );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );

		// Media Library: show which post(s), if any, use each attachment as their featured image.
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_list_column' ), PHP_INT_MAX );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_list_column' ), 10, 2 );

		// Media modal (grid, Insert Media, and the block/classic editor's "Set Featured Image"
		// picker all share this): add a field to the attachment details sidebar showing other
		// posts already using the selected image as their featured image.
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_attachment_field' ), 10, 2 );

		add_action( 'admin_menu', array( __CLASS__, 'add_report_page' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_admin_css' ) );
	}

	/**
	 * Enqueues the block-editor warning script and hands it the full
	 * attachment_id => posts usage map as window.featureUniqueData.
	 *
	 * Unlike the old 'duplicates' map (attachments already used 2+ times),
	 * this includes every attachment used as a featured image at all, since
	 * the "Set Featured Image" picker needs to flag an image the moment it's
	 * used on any other post, before it becomes a duplicate. 'media-views' is
	 * an explicit dependency so wp.media exists before our script patches it.
	 */
	public static function enqueue_block_editor_assets() {
		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'feature-unique-editor',
			plugins_url( 'assets/js/editor.js', __FILE__ ),
			array( 'wp-hooks', 'wp-element', 'wp-data', 'media-views' ),
			'1.2.0',
			true
		);

		$usage = array();

		foreach ( self::get_usage_map() as $thumbnail_id => $posts ) {
			$usage[ $thumbnail_id ] = array_map(
				static function ( $post ) {
					return array(
						'id'       => $post['ID'],
						'title'    => get_the_title( $post['ID'] ),
						'editLink' => get_edit_post_link( $post['ID'], 'raw' ),
					);
				},
				$posts
			);
		}

		wp_localize_script( 'feature-unique-editor', 'featureUniqueData', array( 'usage' => $usage ) );
	}

	/**
	 * Builds (and memoizes) attachment_id => [ { ID, post_title, post_status }, ... ]
	 * for every post of self::POST_TYPE that has a featured image, excluding trash/auto-drafts.
	 */
	private static function get_usage_map() {
		if ( null !== self::$usage_map ) {
			return self::$usage_map;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS thumbnail_id, p.ID AS post_id, p.post_title AS post_title, p.post_status AS post_status
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_thumbnail_id'
				AND p.post_type = %s
				AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				self::POST_TYPE
			)
		);

		$map = array();

		foreach ( $rows as $row ) {
			$thumb_id = (int) $row->thumbnail_id;

			if ( $thumb_id <= 0 ) {
				continue;
			}

			if ( ! isset( $map[ $thumb_id ] ) ) {
				$map[ $thumb_id ] = array();
			}

			$map[ $thumb_id ][] = array(
				'ID'          => (int) $row->post_id,
				'post_title'  => $row->post_title,
				'post_status' => $row->post_status,
			);
		}

		self::$usage_map = $map;

		return self::$usage_map;
	}

	/**
	 * Posts using $thumbnail_id, optionally excluding one post ID (the post being edited).
	 */
	private static function get_other_uses( $thumbnail_id, $exclude_post_id = 0 ) {
		$map   = self::get_usage_map();
		$posts = isset( $map[ (int) $thumbnail_id ] ) ? $map[ (int) $thumbnail_id ] : array();

		if ( $exclude_post_id ) {
			$posts = array_filter(
				$posts,
				static function ( $post ) use ( $exclude_post_id ) {
					return (int) $post['ID'] !== (int) $exclude_post_id;
				}
			);
		}

		return array_values( $posts );
	}

	/**
	 * Appends a warning under the Featured Image box when the current image
	 * is already used as a featured image on other posts.
	 */
	public static function filter_featured_image_box( $content, $post_id, $thumbnail_id ) {
		if ( ! $thumbnail_id ) {
			return $content;
		}

		$other_uses = self::get_other_uses( $thumbnail_id, $post_id );

		if ( empty( $other_uses ) ) {
			return $content;
		}

		$content .= '<p class="feature-unique-warning">';
		$content .= '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
		$content .= esc_html(
			sprintf(
				/* translators: %d: number of other posts using this image */
				_n(
					'Already used as the featured image on %d other post:',
					'Already used as the featured image on %d other posts:',
					count( $other_uses ),
					'feature-unique'
				),
				count( $other_uses )
			)
		);
		$content .= '</p><ul class="feature-unique-warning-list">';

		foreach ( $other_uses as $post ) {
			$content .= '<li>';
			if ( current_user_can( 'edit_post', $post['ID'] ) ) {
				$content .= '<a href="' . esc_url( get_edit_post_link( $post['ID'] ) ) . '">' . esc_html( get_the_title( $post['ID'] ) ) . '</a>';
			} else {
				$content .= esc_html( get_the_title( $post['ID'] ) );
			}
			$content .= '</li>';
		}

		$content .= '</ul>';

		return $content;
	}

	/**
	 * Adds a "Featured Image" status column to the Posts list table.
	 */
	public static function add_list_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['feature_unique'] = __( 'Featured Image', 'feature-unique' );
			}
		}

		return $new;
	}

	/**
	 * Renders the "Featured Image" status column: unique, duplicate (with links), or none.
	 */
	public static function render_list_column( $column, $post_id ) {
		if ( 'feature_unique' !== $column ) {
			return;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			echo '<span class="feature-unique-none">' . esc_html__( 'None', 'feature-unique' ) . '</span>';
			return;
		}

		$other_uses = self::get_other_uses( $thumbnail_id, $post_id );

		if ( empty( $other_uses ) ) {
			echo '<span class="feature-unique-ok">' . esc_html__( 'Unique', 'feature-unique' ) . '</span>';
			return;
		}

		echo '<span class="feature-unique-dup dashicons-before dashicons-warning">';
		echo esc_html(
			sprintf(
				/* translators: %d: number of other posts using this image */
				_n( 'Duplicate (%d other post)', 'Duplicate (%d other posts)', count( $other_uses ), 'feature-unique' ),
				count( $other_uses )
			)
		);
		echo '</span><ul class="feature-unique-dup-list">';

		foreach ( $other_uses as $post ) {
			echo '<li>';
			if ( current_user_can( 'edit_post', $post['ID'] ) ) {
				echo '<a href="' . esc_url( get_edit_post_link( $post['ID'] ) ) . '">' . esc_html( get_the_title( $post['ID'] ) ) . '</a>';
			} else {
				echo esc_html( get_the_title( $post['ID'] ) );
			}
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Adds a "Used as Featured Image On" column to the Media Library list table.
	 */
	public static function add_media_list_column( $columns ) {
		$columns['feature_unique_used_on'] = __( 'Used as Featured Image On', 'feature-unique' );

		return $columns;
	}

	/**
	 * Renders the "Used as Featured Image On" column for each attachment,
	 * listing every post (of self::POST_TYPE) that uses it as a featured image.
	 */
	public static function render_media_list_column( $column, $attachment_id ) {
		if ( 'feature_unique_used_on' !== $column ) {
			return;
		}

		$map   = self::get_usage_map();
		$posts = isset( $map[ (int) $attachment_id ] ) ? $map[ (int) $attachment_id ] : array();

		if ( empty( $posts ) ) {
			echo '<span class="feature-unique-none">' . esc_html__( 'Not used', 'feature-unique' ) . '</span>';
			return;
		}

		echo '<ul class="feature-unique-media-used-on-list">';

		foreach ( $posts as $post ) {
			echo '<li>';
			if ( current_user_can( 'edit_post', $post['ID'] ) ) {
				echo '<a href="' . esc_url( get_edit_post_link( $post['ID'] ) ) . '">' . esc_html( get_the_title( $post['ID'] ) ) . '</a>';
			} else {
				echo esc_html( get_the_title( $post['ID'] ) );
			}
			if ( 'publish' !== $post['post_status'] ) {
				echo ' <span class="feature-unique-status">(' . esc_html( $post['post_status'] ) . ')</span>';
			}
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Adds a "Used as Featured Image On" field to the attachment details sidebar
	 * rendered inside the media modal — including the block editor's and classic
	 * editor's "Set Featured Image" picker, which both reuse this same view.
	 *
	 * Skipped when the image isn't used as a featured image anywhere (or is only
	 * used on the post currently being edited, per the 'post_id' the modal sends).
	 */
	public static function add_attachment_field( $form_fields, $post ) {
		if ( 0 !== strpos( (string) get_post_mime_type( $post ), 'image/' ) ) {
			return $form_fields;
		}

		$exclude_post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$other_uses      = self::get_other_uses( $post->ID, $exclude_post_id );

		if ( empty( $other_uses ) ) {
			return $form_fields;
		}

		$html = '<p class="feature-unique-modal-warning">';
		$html .= esc_html(
			sprintf(
				/* translators: %d: number of other posts using this image as a featured image */
				_n(
					'Already used as the featured image on %d other post:',
					'Already used as the featured image on %d other posts:',
					count( $other_uses ),
					'feature-unique'
				),
				count( $other_uses )
			)
		);
		$html .= '</p><ul class="feature-unique-modal-used-on-list">';

		foreach ( $other_uses as $used_post ) {
			$html .= '<li>';
			if ( current_user_can( 'edit_post', $used_post['ID'] ) ) {
				$html .= '<a href="' . esc_url( get_edit_post_link( $used_post['ID'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( get_the_title( $used_post['ID'] ) ) . '</a>';
			} else {
				$html .= esc_html( get_the_title( $used_post['ID'] ) );
			}
			$html .= '</li>';
		}

		$html .= '</ul>';

		$form_fields['feature_unique_used_on'] = array(
			'label' => __( 'Featured Image Use', 'feature-unique' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	public static function add_report_page() {
		add_management_page(
			__( 'Find Duplicate Featured Images', 'feature-unique' ),
			__( 'Find Duplicate Featured Images', 'feature-unique' ),
			'edit_posts',
			'feature-unique-report',
			array( __CLASS__, 'render_report_page' )
		);
	}

	public static function render_report_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$map = self::get_usage_map();

		$duplicates = array_filter(
			$map,
			static function ( $posts ) {
				return count( $posts ) > 1;
			}
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Find Duplicate Featured Images', 'feature-unique' ) . '</h1>';

		if ( empty( $duplicates ) ) {
			echo '<p>' . esc_html__( 'Great! No image is currently used as the featured image on more than one post.', 'feature-unique' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of images reused */
				_n(
					'%d image is used as the featured image on more than one post:',
					'%d images are used as the featured image on more than one post:',
					count( $duplicates ),
					'feature-unique'
				),
				count( $duplicates )
			)
		) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:80px;">' . esc_html__( 'Image', 'feature-unique' ) . '</th>';
		echo '<th>' . esc_html__( 'Used on', 'feature-unique' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $duplicates as $thumbnail_id => $posts ) {
			echo '<tr>';
			echo '<td>';
			$attachment_link = current_user_can( 'upload_files' ) ? get_edit_post_link( $thumbnail_id ) : '';
			if ( $attachment_link ) {
				echo '<a href="' . esc_url( $attachment_link ) . '">';
			}
			echo wp_get_attachment_image( $thumbnail_id, array( 60, 60 ) );
			if ( $attachment_link ) {
				echo '</a>';
			}
			echo '</td>';

			echo '<td><ul class="feature-unique-report-list">';
			foreach ( $posts as $post ) {
				echo '<li>';
				if ( current_user_can( 'edit_post', $post['ID'] ) ) {
					echo '<a href="' . esc_url( get_edit_post_link( $post['ID'] ) ) . '">' . esc_html( get_the_title( $post['ID'] ) ) . '</a>';
				} else {
					echo esc_html( get_the_title( $post['ID'] ) );
				}
				echo ' <span class="feature-unique-status">(' . esc_html( $post['post_status'] ) . ')</span>';
				echo '</li>';
			}
			echo '</ul></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	public static function print_admin_css() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$relevant = ( 'post' === $screen->base && self::POST_TYPE === $screen->post_type )
			|| ( 'edit' === $screen->base && self::POST_TYPE === $screen->post_type )
			|| 'upload' === $screen->base
			|| 'tools_page_feature-unique-report' === $screen->id;

		if ( ! $relevant ) {
			return;
		}
		?>
		<style>
			.feature-unique-warning,
			.feature-unique-block-warning p,
			.feature-unique-modal-warning,
			.feature-unique-picker-warning p {
				color: #b32d2e;
				font-weight: 600;
				margin: 8px 0 4px;
			}
			.feature-unique-block-warning ul {
				margin: 0 0 8px 1.2em;
				list-style: disc;
				font-size: 12px;
			}
			.feature-unique-picker-warning {
				background: #fcf0f1;
				border-left: 4px solid #b32d2e;
				padding: 8px 12px;
				margin: 0 0 12px;
			}
			.feature-unique-picker-warning ul {
				margin: 0 0 4px 1.2em;
				list-style: disc;
				font-size: 12px;
			}
			.feature-unique-warning-list,
			.feature-unique-dup-list,
			.feature-unique-report-list,
			.feature-unique-media-used-on-list,
			.feature-unique-modal-used-on-list {
				margin: 0 0 0 1.2em;
				list-style: disc;
				font-size: 12px;
			}
			.feature-unique-dup {
				color: #b32d2e;
				font-weight: 600;
			}
			.feature-unique-ok {
				color: #646970;
			}
			.feature-unique-none {
				color: #a7aaad;
			}
			.feature-unique-status {
				color: #646970;
				font-style: italic;
			}
		</style>
		<?php
	}
}

Feature_Unique::init();
