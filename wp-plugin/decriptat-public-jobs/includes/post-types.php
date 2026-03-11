<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the public job custom post type.
 */
function decriptat_pj_register_post_type() {
	$labels = array(
		'name'          => __( 'Public Jobs', 'decriptat-public-jobs' ),
		'singular_name' => __( 'Public Job', 'decriptat-public-jobs' ),
		'menu_name'     => __( 'Public Jobs', 'decriptat-public-jobs' ),
		'add_new_item'  => __( 'Add New Public Job', 'decriptat-public-jobs' ),
		'edit_item'     => __( 'Edit Public Job', 'decriptat-public-jobs' ),
		'new_item'      => __( 'New Public Job', 'decriptat-public-jobs' ),
		'view_item'     => __( 'View Public Job', 'decriptat-public-jobs' ),
		'search_items'  => __( 'Search Public Jobs', 'decriptat-public-jobs' ),
	);

	register_post_type(
		'public_job',
		array(
			'labels'       => $labels,
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-id',
			'rewrite'      => array( 'slug' => 'public-jobs' ),
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
		)
	);
}
add_action( 'init', 'decriptat_pj_register_post_type' );

/**
 * Register taxonomies for public jobs.
 */
function decriptat_pj_register_taxonomies() {
	register_taxonomy(
		'institution',
		'public_job',
		array(
			'label'        => __( 'Institutions', 'decriptat-public-jobs' ),
			'public'       => true,
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'institution' ),
		)
	);

	register_taxonomy(
		'job_category',
		'public_job',
		array(
			'label'        => __( 'Job Categories', 'decriptat-public-jobs' ),
			'public'       => true,
			'hierarchical' => true,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'job-category' ),
		)
	);
}
add_action( 'init', 'decriptat_pj_register_taxonomies' );

/**
 * Ensure public_job archive is ordered newest first.
 *
 * @param WP_Query $query Query object.
 */
function decriptat_pj_archive_order( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_post_type_archive( 'public_job' ) ) {
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );

		if ( isset( $_GET['q'] ) ) {
			$search = sanitize_text_field( wp_unslash( $_GET['q'] ) );
			if ( '' !== $search ) {
				$query->set( 's', $search );
			}
		}

		$tax_query = array();

		if ( isset( $_GET['job_category'] ) ) {
			$job_category = sanitize_title( wp_unslash( $_GET['job_category'] ) );
			if ( '' !== $job_category ) {
				$tax_query[] = array(
					'taxonomy' => 'job_category',
					'field'    => 'slug',
					'terms'    => array( $job_category ),
				);
			}
		}

		if ( isset( $_GET['institution'] ) ) {
			$institution = sanitize_title( wp_unslash( $_GET['institution'] ) );
			if ( '' !== $institution ) {
				$tax_query[] = array(
					'taxonomy' => 'institution',
					'field'    => 'slug',
					'terms'    => array( $institution ),
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}

		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
		if ( ! in_array( $status_filter, array( 'active', 'expired', 'all' ), true ) ) {
			$status_filter = 'active';
		}

		$today = current_time( 'Y-m-d' );
		if ( 'active' === $status_filter ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'AND',
					array(
						'relation' => 'OR',
						array(
							'key'     => 'expired',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'expired',
							'value'   => '1',
							'compare' => '!=',
						),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'deadline',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => 'deadline',
							'value'   => '',
							'compare' => '=',
						),
						array(
							'key'     => 'deadline',
							'value'   => $today,
							'type'    => 'DATE',
							'compare' => '>=',
						),
					),
				)
			);
		} elseif ( 'expired' === $status_filter ) {
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => 'expired',
						'value'   => '1',
						'compare' => '=',
					),
					array(
						'key'     => 'deadline',
						'value'   => $today,
						'type'    => 'DATE',
						'compare' => '<',
					),
				)
			);
		}
	}
}
add_action( 'pre_get_posts', 'decriptat_pj_archive_order' );
