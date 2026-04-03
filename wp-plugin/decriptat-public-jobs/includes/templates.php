<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue frontend assets for plugin templates and shortcode pages.
 */
function decriptat_pj_enqueue_assets() {
	wp_enqueue_style(
		'decriptat-public-jobs-frontend',
		DECRIPTAT_PJ_PLUGIN_URL . 'assets/css/public-jobs.css',
		array(),
		'1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'decriptat_pj_enqueue_assets' );

/**
 * Format dates from crawler meta as localized display date.
 *
 * @param string $raw_date Raw date from meta.
 * @return string
 */
function decriptat_pj_format_date( $raw_date ) {
	if ( empty( $raw_date ) ) {
		return '';
	}

	$timestamp = strtotime( $raw_date );
	if ( false === $timestamp ) {
		return $raw_date;
	}

	return wp_date( 'd-m-Y', $timestamp );
}

/**
 * Load plugin templates for public_job post type.
 *
 * @param string $template Current template.
 * @return string
 */
function decriptat_pj_template_include( $template ) {
	if ( is_post_type_archive( 'public_job' ) ) {
		$archive_template = DECRIPTAT_PJ_PLUGIN_DIR . 'templates/archive-public_job.php';
		if ( file_exists( $archive_template ) ) {
			return $archive_template;
		}
	}

	if ( is_singular( 'public_job' ) ) {
		$single_template = DECRIPTAT_PJ_PLUGIN_DIR . 'templates/single-public_job.php';
		if ( file_exists( $single_template ) ) {
			return $single_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'decriptat_pj_template_include' );
