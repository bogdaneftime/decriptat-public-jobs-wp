<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a public jobs list.
 *
 * @param bool $it_only Whether to filter only IT jobs.
 * @return string
 */
function decriptat_pj_render_jobs_shortcode( $it_only = false ) {
	$args = array(
		'post_type'      => 'public_job',
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	if ( $it_only ) {
		$args['meta_query'] = array(
			array(
				'key'     => 'is_it',
				'value'   => '1',
				'compare' => '=',
			),
		);
	}

	$query = new WP_Query( $args );

	ob_start();
	?>
	<div class="decriptat-pj-shortcode-list">
		<?php if ( $query->have_posts() ) : ?>
			<ul class="decriptat-pj-jobs">
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					$location = get_post_meta( get_the_ID(), 'location', true );
					$deadline = get_post_meta( get_the_ID(), 'deadline', true );
					?>
					<li class="decriptat-pj-job">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						<?php if ( ! empty( $location ) ) : ?>
							<span> - <?php echo esc_html( $location ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $deadline ) ) : ?>
							<span> (<?php echo esc_html( $deadline ); ?>)</span>
						<?php endif; ?>
					</li>
				<?php endwhile; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'Nu exista joburi disponibile momentan.', 'decriptat-public-jobs' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	wp_reset_postdata();

	return ob_get_clean();
}

/**
 * Shortcode for IT public jobs.
 *
 * @return string
 */
function decriptat_pj_shortcode_joburi_it() {
	return decriptat_pj_render_jobs_shortcode( true );
}
add_shortcode( 'decriptat_joburi_it', 'decriptat_pj_shortcode_joburi_it' );

/**
 * Shortcode for all public jobs.
 *
 * @return string
 */
function decriptat_pj_shortcode_joburi_toate() {
	return decriptat_pj_render_jobs_shortcode( false );
}
add_shortcode( 'decriptat_joburi_toate', 'decriptat_pj_shortcode_joburi_toate' );
