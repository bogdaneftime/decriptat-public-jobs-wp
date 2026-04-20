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

	register_taxonomy(
		'job_city',
		'public_job',
		array(
			'label'        => __( 'Cities', 'decriptat-public-jobs' ),
			'public'       => true,
			'hierarchical' => false,
			'show_in_rest' => true,
			'rewrite'      => array( 'slug' => 'job-city' ),
		)
	);
}
add_action( 'init', 'decriptat_pj_register_taxonomies' );

/**
 * Backfill the default city for existing public jobs.
 */
function decriptat_pj_backfill_default_city_term() {
	$option_key = 'decriptat_pj_default_city_backfilled_v1';
	if ( get_option( $option_key ) ) {
		return;
	}

	$default_city = wp_insert_term( 'Bucuresti', 'job_city' );
	if ( is_wp_error( $default_city ) && 'term_exists' !== $default_city->get_error_code() ) {
		return;
	}

	$term = get_term_by( 'slug', 'bucuresti', 'job_city' );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	$jobs = get_posts(
		array(
			'post_type'      => 'public_job',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $jobs as $post_id ) {
		$existing_terms = wp_get_post_terms( $post_id, 'job_city', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $existing_terms ) || ! empty( $existing_terms ) ) {
			continue;
		}

		wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'job_city', false );
	}

	update_option( $option_key, 1, false );
}
add_action( 'init', 'decriptat_pj_backfill_default_city_term', 20 );

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
		$query->set( 'posts_per_page', 100 );

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

		if ( isset( $_GET['job_city'] ) ) {
			$job_city = sanitize_title( wp_unslash( $_GET['job_city'] ) );
			if ( '' !== $job_city ) {
				$tax_query[] = array(
					'taxonomy' => 'job_city',
					'field'    => 'slug',
					'terms'    => array( $job_city ),
				);
			}
		}

		if ( ! empty( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}

	}
}
add_action( 'pre_get_posts', 'decriptat_pj_archive_order' );

/**
 * Keep only one job_category term per public_job post.
 *
 * @param int $post_id Post ID.
 */
function decriptat_pj_enforce_single_job_category( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$terms = wp_get_post_terms( $post_id, 'job_category' );
	if ( is_wp_error( $terms ) || count( $terms ) <= 1 ) {
		return;
	}

	$first_term = reset( $terms );
	if ( ! $first_term || empty( $first_term->term_id ) ) {
		return;
	}

	wp_set_post_terms( $post_id, array( (int) $first_term->term_id ), 'job_category', false );
}
add_action( 'save_post_public_job', 'decriptat_pj_enforce_single_job_category' );
