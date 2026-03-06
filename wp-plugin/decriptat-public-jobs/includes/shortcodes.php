<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get status labels and visual state for a public job.
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>
 */
function decriptat_pj_get_job_state( $post_id ) {
	$deadline_raw = get_post_meta( $post_id, 'deadline', true );
	$expired_meta = rest_sanitize_boolean( get_post_meta( $post_id, 'expired', true ) );
	$today        = current_time( 'Y-m-d' );
	$is_expired   = false;
	$is_active    = false;

	if ( ! empty( $deadline_raw ) ) {
		$is_expired = ( $deadline_raw < $today );
		$is_active  = ! $is_expired;
	}

	if ( $expired_meta ) {
		$is_expired = true;
		$is_active  = false;
	}

	$label = '';
	if ( $is_expired ) {
		$label = __( 'Expirat', 'decriptat-public-jobs' );
	} elseif ( $is_active ) {
		$label = __( 'Activ', 'decriptat-public-jobs' );
	}

	return array(
		'is_expired' => $is_expired,
		'is_active'  => $is_active,
		'label'      => $label,
		'deadline'   => $deadline_raw,
	);
}

/**
 * Sort jobs: active first, expired second, then newest deadline first.
 *
 * @param WP_Post $a First post.
 * @param WP_Post $b Second post.
 * @return int
 */
function decriptat_pj_sort_jobs( $a, $b ) {
	$state_a = decriptat_pj_get_job_state( $a->ID );
	$state_b = decriptat_pj_get_job_state( $b->ID );

	$group_a = $state_a['is_expired'] ? 1 : 0;
	$group_b = $state_b['is_expired'] ? 1 : 0;
	if ( $group_a !== $group_b ) {
		return $group_a - $group_b;
	}

	$deadline_a = ! empty( $state_a['deadline'] ) ? strtotime( $state_a['deadline'] ) : 0;
	$deadline_b = ! empty( $state_b['deadline'] ) ? strtotime( $state_b['deadline'] ) : 0;
	if ( $deadline_a !== $deadline_b ) {
		return $deadline_b - $deadline_a;
	}

	return 0;
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
		'posts_per_page' => 100,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	$search_query = '';
	if ( isset( $_GET['q'] ) ) {
		$search_query = sanitize_text_field( wp_unslash( $_GET['q'] ) );
		if ( ! empty( $search_query ) ) {
			$args['s'] = $search_query;
		}
	}

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
	$posts = $query->posts;

	usort( $posts, 'decriptat_pj_sort_jobs' );

	ob_start();
	?>
	<style>
		.decriptat-pj-search { margin-bottom: 14px; }
		.decriptat-pj-search input[type="search"] { width: 100%; max-width: 360px; padding: 8px 10px; border: 1px solid #d2d7df; border-radius: 6px; }
		.decriptat-pj-jobs { list-style: none; margin: 0; padding: 0; }
		.decriptat-pj-job { padding: 12px; border: 1px solid #e6e9ef; border-radius: 8px; margin-bottom: 10px; background: #fff; transition: opacity 0.2s ease; }
		.decriptat-pj-job.is-expired { opacity: 0.6; }
		.decriptat-pj-job-title { display: inline-block; font-weight: 600; margin-bottom: 8px; }
		.decriptat-pj-badges { display: flex; flex-wrap: wrap; gap: 6px; }
		.decriptat-pj-badge { display: inline-block; font-size: 12px; line-height: 1; padding: 5px 8px; border-radius: 999px; background: #f3f6fb; color: #22304a; border: 1px solid #dfe5ef; }
		.decriptat-pj-badge-status-active { background: #edf8ef; color: #1f5b2c; border-color: #cde7d2; }
		.decriptat-pj-badge-status-expired { background: #fbecec; color: #7b1e1e; border-color: #f3cccc; }
	</style>
	<div class="decriptat-pj-shortcode-list">
		<form class="decriptat-pj-search" method="get">
			<input type="search" name="q" placeholder="<?php esc_attr_e( 'Cauta joburi...', 'decriptat-public-jobs' ); ?>" value="<?php echo esc_attr( $search_query ); ?>" />
		</form>
		<?php if ( ! empty( $posts ) ) : ?>
			<ul class="decriptat-pj-jobs">
				<?php
				foreach ( $posts as $job_post ) :
					$location    = get_post_meta( $job_post->ID, 'location', true );
					$state       = decriptat_pj_get_job_state( $job_post->ID );
					$institutions = get_the_terms( $job_post->ID, 'institution' );
					$institution = '';
					if ( ! is_wp_error( $institutions ) && ! empty( $institutions ) ) {
						$institution = $institutions[0]->name;
					}
					$item_class = $state['is_expired'] ? 'decriptat-pj-job is-expired' : 'decriptat-pj-job';
					?>
					<li class="<?php echo esc_attr( $item_class ); ?>">
						<a class="decriptat-pj-job-title" href="<?php echo esc_url( get_permalink( $job_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $job_post->ID ) ); ?></a>
						<div class="decriptat-pj-badges">
							<?php if ( ! empty( $institution ) ) : ?>
								<span class="decriptat-pj-badge"><?php echo esc_html( $institution ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $state['deadline'] ) ) : ?>
								<span class="decriptat-pj-badge"><?php echo esc_html( $state['deadline'] ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $location ) ) : ?>
								<span class="decriptat-pj-badge"><?php echo esc_html( $location ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $state['label'] ) ) : ?>
								<?php $status_class = $state['is_expired'] ? 'decriptat-pj-badge decriptat-pj-badge-status-expired' : 'decriptat-pj-badge decriptat-pj-badge-status-active'; ?>
								<span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $state['label'] ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
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
