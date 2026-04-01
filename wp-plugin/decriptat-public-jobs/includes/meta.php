<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register public job meta fields.
 */
function decriptat_pj_register_meta() {
	register_post_meta(
		'public_job',
		'source_url',
		array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	register_post_meta(
		'public_job',
		'published_date',
		array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	register_post_meta(
		'public_job',
		'deadline',
		array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	register_post_meta(
		'public_job',
		'location',
		array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		)
	);

	register_post_meta(
		'public_job',
		'is_it',
		array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		)
	);

	register_post_meta(
		'public_job',
		'expired',
		array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		)
	);

	register_post_meta(
		'public_job',
		'manual_is_active',
		array(
			'type'              => 'boolean',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		)
	);
}
add_action( 'init', 'decriptat_pj_register_meta' );

/**
 * Add admin meta box for manually controlling job activity.
 */
function decriptat_pj_add_status_meta_box() {
	add_meta_box(
		'decriptat-pj-job-status',
		__( 'Status job', 'decriptat-public-jobs' ),
		'decriptat_pj_render_status_meta_box',
		'public_job',
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'decriptat_pj_add_status_meta_box' );

/**
 * Render admin controls for job status.
 *
 * @param WP_Post $post Current post object.
 */
function decriptat_pj_render_status_meta_box( $post ) {
	$manual_is_active      = get_post_meta( $post->ID, 'manual_is_active', true );
	if ( '' === $manual_is_active ) {
		$expired_meta      = rest_sanitize_boolean( get_post_meta( $post->ID, 'expired', true ) );
		$manual_is_active  = ! $expired_meta;
	} else {
		$manual_is_active = rest_sanitize_boolean( $manual_is_active );
	}

	wp_nonce_field( 'decriptat_pj_save_status_meta_box', 'decriptat_pj_status_meta_box_nonce' );
	?>
	<p>
		<label>
			<input type="checkbox" name="decriptat_pj_manual_is_active" value="1" <?php checked( $manual_is_active ); ?> />
			<?php esc_html_e( 'Job activ', 'decriptat-public-jobs' ); ?>
		</label>
	</p>
	<p class="description">
		<?php esc_html_e( 'Daca debifezi, jobul este tratat ca expirat si apare doar in filtrele pentru joburi expirate.', 'decriptat-public-jobs' ); ?>
	</p>
	<?php
}

/**
 * Save manual status settings for public jobs.
 *
 * @param int $post_id Post ID.
 */
function decriptat_pj_save_status_meta_box( $post_id ) {
	if ( ! isset( $_POST['decriptat_pj_status_meta_box_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['decriptat_pj_status_meta_box_nonce'] ) ), 'decriptat_pj_save_status_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$manual_is_active      = isset( $_POST['decriptat_pj_manual_is_active'] );

	update_post_meta( $post_id, 'manual_is_active', $manual_is_active ? 1 : 0 );
	update_post_meta( $post_id, 'expired', $manual_is_active ? 0 : 1 );
	delete_post_meta( $post_id, 'manual_status_enabled' );
}
add_action( 'save_post_public_job', 'decriptat_pj_save_status_meta_box' );
