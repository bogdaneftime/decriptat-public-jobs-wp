<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
