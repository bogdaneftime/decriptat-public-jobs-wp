<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add custom columns to the public jobs list.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function decriptat_pj_admin_columns( $columns ) {
	$date_column = isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'decriptat-public-jobs' );
	unset( $columns['date'] );

	$columns['decriptat_pj_status']      = __( 'Status', 'decriptat-public-jobs' );
	$columns['decriptat_pj_institution'] = __( 'Institutie', 'decriptat-public-jobs' );
	$columns['decriptat_pj_deadline']    = __( 'Termen', 'decriptat-public-jobs' );
	$columns['date']                     = $date_column;

	return $columns;
}
add_filter( 'manage_public_job_posts_columns', 'decriptat_pj_admin_columns' );

/**
 * Render custom column values for public jobs.
 *
 * @param string $column_name Column key.
 * @param int    $post_id     Post ID.
 */
function decriptat_pj_render_admin_column( $column_name, $post_id ) {
	if ( 'decriptat_pj_status' === $column_name ) {
		$state = function_exists( 'decriptat_pj_get_job_state' )
			? decriptat_pj_get_job_state( $post_id )
			: array(
				'is_expired' => false,
				'label'      => __( 'Activ', 'decriptat-public-jobs' ),
			);
		$manual_is_active = get_post_meta( $post_id, 'manual_is_active', true );
		$is_manual        = '' !== $manual_is_active;
		$badge_style      = $state['is_expired']
			? 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;'
			: 'background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;';

		echo '<span style="display:inline-block;padding:4px 8px;border-radius:999px;font-weight:600;' . esc_attr( $badge_style ) . '">';
		echo esc_html( $state['label'] );
		echo '</span>';

		if ( $is_manual ) {
			echo '<br /><small>' . esc_html__( 'Setat manual', 'decriptat-public-jobs' ) . '</small>';
		}

		echo '<div class="hidden" id="decriptat-pj-status-data-' . esc_attr( $post_id ) . '">';
		echo '<span class="decriptat-pj-manual-active-value">' . esc_html( $state['is_active'] ? '1' : '0' ) . '</span>';
		echo '</div>';

		return;
	}

	if ( 'decriptat_pj_institution' === $column_name ) {
		$terms = get_the_terms( $post_id, 'institution' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '&mdash;';
			return;
		}

		echo esc_html( $terms[0]->name );
		return;
	}

	if ( 'decriptat_pj_deadline' === $column_name ) {
		$deadline = get_post_meta( $post_id, 'deadline', true );
		if ( empty( $deadline ) ) {
			echo '&mdash;';
			return;
		}

		if ( function_exists( 'decriptat_pj_format_date' ) ) {
			echo esc_html( decriptat_pj_format_date( $deadline ) );
			return;
		}

		echo esc_html( $deadline );
	}
}
add_action( 'manage_public_job_posts_custom_column', 'decriptat_pj_render_admin_column', 10, 2 );

/**
 * Render quick edit fields for public jobs.
 *
 * @param string $column_name Column name.
 * @param string $post_type   Post type.
 */
function decriptat_pj_quick_edit_status_field( $column_name, $post_type ) {
	if ( 'public_job' !== $post_type || 'decriptat_pj_status' !== $column_name ) {
		return;
	}

	wp_nonce_field( 'decriptat_pj_quick_edit_status', 'decriptat_pj_quick_edit_status_nonce' );
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col">
			<label class="alignleft">
				<span class="title"><?php esc_html_e( 'Status job', 'decriptat-public-jobs' ); ?></span>
				<span class="input-text-wrap">
					<label>
						<input type="checkbox" name="decriptat_pj_manual_is_active_quick" value="1" />
						<?php esc_html_e( 'Job activ', 'decriptat-public-jobs' ); ?>
					</label>
				</span>
			</label>
		</div>
	</fieldset>
	<?php
}
add_action( 'quick_edit_custom_box', 'decriptat_pj_quick_edit_status_field', 10, 2 );

/**
 * Enqueue admin script for quick edit status sync.
 *
 * @param string $hook_suffix Admin hook suffix.
 */
function decriptat_pj_admin_enqueue_scripts( $hook_suffix ) {
	if ( 'edit.php' !== $hook_suffix ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'public_job' !== $screen->post_type ) {
		return;
	}

	wp_add_inline_script(
		'inline-edit-post',
		"(function($){\n" .
		"if(typeof inlineEditPost === 'undefined'){return;}\n" .
		"var wpInlineEdit = inlineEditPost.edit;\n" .
		"inlineEditPost.edit = function(id){\n" .
		"\twpInlineEdit.apply(this, arguments);\n" .
		"\tvar postId = 0;\n" .
		"\tif(typeof id === 'object'){ postId = parseInt(this.getId(id), 10); } else { postId = parseInt(id, 10); }\n" .
		"\tif(!postId){ return; }\n" .
		"\tvar editRow = $('#edit-' + postId);\n" .
		"\tvar postRow = $('#post-' + postId);\n" .
		"\tvar value = postRow.find('.decriptat-pj-manual-active-value').text();\n" .
		"\teditRow.find('input[name=\"decriptat_pj_manual_is_active_quick\"]').prop('checked', value === '1');\n" .
		"};\n" .
		"})(jQuery);"
	);
}
add_action( 'admin_enqueue_scripts', 'decriptat_pj_admin_enqueue_scripts' );

/**
 * Add a status filter dropdown in the public jobs admin list.
 */
function decriptat_pj_admin_status_filter() {
	global $typenow;

	if ( 'public_job' !== $typenow ) {
		return;
	}

	$current_value = isset( $_GET['decriptat_pj_admin_status'] ) ? sanitize_key( wp_unslash( $_GET['decriptat_pj_admin_status'] ) ) : '';
	?>
	<select name="decriptat_pj_admin_status">
		<option value=""><?php esc_html_e( 'Toate statusurile', 'decriptat-public-jobs' ); ?></option>
		<option value="active" <?php selected( $current_value, 'active' ); ?>><?php esc_html_e( 'Active', 'decriptat-public-jobs' ); ?></option>
		<option value="expired" <?php selected( $current_value, 'expired' ); ?>><?php esc_html_e( 'Expirate', 'decriptat-public-jobs' ); ?></option>
	</select>
	<?php
}
add_action( 'restrict_manage_posts', 'decriptat_pj_admin_status_filter' );

/**
 * Filter the public jobs admin list by status.
 *
 * @param WP_Query $query Query object.
 */
function decriptat_pj_filter_admin_jobs_by_status( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'public_job' !== $query->get( 'post_type' ) ) {
		return;
	}

	$status_filter = isset( $_GET['decriptat_pj_admin_status'] ) ? sanitize_key( wp_unslash( $_GET['decriptat_pj_admin_status'] ) ) : '';
	if ( ! in_array( $status_filter, array( 'active', 'expired' ), true ) ) {
		return;
	}

	$query->set(
		'meta_query',
		array(
			array(
				'key'     => 'expired',
				'value'   => 'expired' === $status_filter ? '1' : '0',
				'compare' => '=',
			),
		)
	);
}
add_action( 'pre_get_posts', 'decriptat_pj_filter_admin_jobs_by_status' );

/**
 * Register bulk actions for the public jobs list.
 *
 * @param array<string, string> $bulk_actions Existing bulk actions.
 * @return array<string, string>
 */
function decriptat_pj_register_bulk_actions( $bulk_actions ) {
	$bulk_actions['decriptat_pj_mark_active']  = __( 'Marcheaza active', 'decriptat-public-jobs' );
	$bulk_actions['decriptat_pj_mark_expired'] = __( 'Marcheaza expirate', 'decriptat-public-jobs' );

	return $bulk_actions;
}
add_filter( 'bulk_actions-edit-public_job', 'decriptat_pj_register_bulk_actions' );

/**
 * Handle public jobs bulk actions.
 *
 * @param string $redirect_to Redirect URL.
 * @param string $doaction    Action name.
 * @param array  $post_ids    Selected post IDs.
 * @return string
 */
function decriptat_pj_handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
	if ( ! in_array( $doaction, array( 'decriptat_pj_mark_active', 'decriptat_pj_mark_expired' ), true ) ) {
		return $redirect_to;
	}

	$mark_active = 'decriptat_pj_mark_active' === $doaction;
	$updated     = 0;

	foreach ( $post_ids as $post_id ) {
		if ( 'public_job' !== get_post_type( $post_id ) ) {
			continue;
		}

		update_post_meta( $post_id, 'manual_is_active', $mark_active ? 1 : 0 );
		update_post_meta( $post_id, 'expired', $mark_active ? 0 : 1 );
		++$updated;
	}

	return add_query_arg(
		array(
			'decriptat_pj_bulk_updated' => $updated,
			'decriptat_pj_bulk_action'  => $mark_active ? 'active' : 'expired',
		),
		$redirect_to
	);
}
add_filter( 'handle_bulk_actions-edit-public_job', 'decriptat_pj_handle_bulk_actions', 10, 3 );

/**
 * Save quick edit status changes for public jobs.
 *
 * @param int $post_id Post ID.
 */
function decriptat_pj_save_quick_edit_status( $post_id ) {
	if ( ! isset( $_POST['decriptat_pj_quick_edit_status_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['decriptat_pj_quick_edit_status_nonce'] ) ), 'decriptat_pj_quick_edit_status' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( 'public_job' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$manual_is_active = isset( $_POST['decriptat_pj_manual_is_active_quick'] );
	update_post_meta( $post_id, 'manual_is_active', $manual_is_active ? 1 : 0 );
	update_post_meta( $post_id, 'expired', $manual_is_active ? 0 : 1 );
}
add_action( 'save_post_public_job', 'decriptat_pj_save_quick_edit_status', 20 );

/**
 * Show a notice after public job bulk updates.
 */
function decriptat_pj_bulk_admin_notice() {
	if ( ! isset( $_GET['decriptat_pj_bulk_updated'], $_GET['decriptat_pj_bulk_action'] ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'edit-public_job' !== $screen->id ) {
		return;
	}

	$updated = absint( $_GET['decriptat_pj_bulk_updated'] );
	$action  = sanitize_key( wp_unslash( $_GET['decriptat_pj_bulk_action'] ) );
	$message = 'active' === $action
		? sprintf(
			/* translators: %d number of jobs. */
			_n( '%d job a fost marcat activ.', '%d joburi au fost marcate active.', $updated, 'decriptat-public-jobs' ),
			$updated
		)
		: sprintf(
			/* translators: %d number of jobs. */
			_n( '%d job a fost marcat expirat.', '%d joburi au fost marcate expirate.', $updated, 'decriptat-public-jobs' ),
			$updated
		);

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'decriptat_pj_bulk_admin_notice' );
