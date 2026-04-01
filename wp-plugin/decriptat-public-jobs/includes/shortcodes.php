<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a job-related date string into a timestamp.
 *
 * @param string $raw_date Raw date from meta.
 * @return int|false
 */
function decriptat_pj_parse_job_timestamp( $raw_date ) {
	if ( empty( $raw_date ) ) {
		return false;
	}

	return strtotime( $raw_date );
}

/**
 * Determine if a timestamp falls in the current or previous month.
 *
 * @param int $timestamp Unix timestamp.
 * @return bool
 */
function decriptat_pj_is_in_recent_job_window( $timestamp ) {
	$current_month = current_time( 'Y-m' );
	$previous_month = wp_date( 'Y-m', strtotime( '-1 month', current_time( 'timestamp' ) ) );
	$target_month   = wp_date( 'Y-m', $timestamp );

	return in_array( $target_month, array( $current_month, $previous_month ), true );
}

/**
 * Resolve the fallback reference date used when application period is unknown.
 *
 * @param int $post_id Post ID.
 * @return int|false
 */
function decriptat_pj_get_fallback_activity_timestamp( $post_id ) {
	$published_timestamp = decriptat_pj_parse_job_timestamp( get_post_meta( $post_id, 'published_date', true ) );
	if ( false !== $published_timestamp ) {
		return $published_timestamp;
	}

	$post_timestamp = get_post_timestamp( $post_id, 'date' );
	if ( false !== $post_timestamp ) {
		return $post_timestamp;
	}

	return false;
}

/**
 * Get status labels and visual state for a public job.
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>
 */
function decriptat_pj_get_job_state( $post_id ) {
	$deadline_raw        = get_post_meta( $post_id, 'deadline', true );
	$expired_meta        = rest_sanitize_boolean( get_post_meta( $post_id, 'expired', true ) );
	$manual_is_active    = get_post_meta( $post_id, 'manual_is_active', true );
	$has_manual_status   = '' !== $manual_is_active;
	$manual_is_active    = $has_manual_status ? rest_sanitize_boolean( $manual_is_active ) : true;
	$today_ts            = strtotime( current_time( 'Y-m-d' ) );
	$deadline_ts         = decriptat_pj_parse_job_timestamp( $deadline_raw );
	$fallback_timestamp  = decriptat_pj_get_fallback_activity_timestamp( $post_id );
	$is_expired          = false;
	$is_active           = true;

	if ( $has_manual_status ) {
		$is_active  = $manual_is_active;
		$is_expired = ! $manual_is_active;
	} elseif ( false !== $deadline_ts ) {
		$is_expired = ( $deadline_ts < $today_ts );
		$is_active  = ! $is_expired;
	} elseif ( $expired_meta ) {
		// Respect explicit expired flag when upstream marks the listing as closed.
		$is_expired = true;
		$is_active  = false;
	} elseif ( false !== $fallback_timestamp ) {
		$is_active  = decriptat_pj_is_in_recent_job_window( $fallback_timestamp );
		$is_expired = ! $is_active;
	} else {
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
 * Keep only distinct posts by post ID.
 *
 * @param array<int, WP_Post> $posts Posts list.
 * @return array<int, WP_Post>
 */
function decriptat_pj_unique_posts( $posts ) {
	$seen    = array();
	$unique  = array();
	foreach ( $posts as $post_item ) {
		$post_id = (int) $post_item->ID;
		if ( isset( $seen[ $post_id ] ) ) {
			continue;
		}
		$seen[ $post_id ] = true;
		$unique[]         = $post_item;
	}
	return $unique;
}

/**
 * Keep only one visible job for equivalent entries.
 * Priority: same source_url, then same institution + normalized title + publication date.
 *
 * @param array<int, WP_Post> $posts Posts list.
 * @return array<int, WP_Post>
 */
function decriptat_pj_unique_jobs( $posts ) {
	$seen   = array();
	$unique = array();

	foreach ( $posts as $post_item ) {
		$source_url = get_post_meta( $post_item->ID, 'source_url', true );
		$key_source = ! empty( $source_url ) ? strtolower( trim( $source_url ) ) : '';
		$title_raw  = wp_strip_all_tags( get_the_title( $post_item->ID ) );
		$title_norm = strtolower( trim( $title_raw ) );
		$title_norm = preg_replace( '/^\[[^\]]+\]\s*/', '', $title_norm );
		$title_norm = preg_replace( '/\s*[-–]\s*bucuresti\s*$/u', '', $title_norm );
		$title_norm = preg_replace( '/\s+/', ' ', $title_norm );
		$title_norm = trim( $title_norm );

		$institutions      = get_the_terms( $post_item->ID, 'institution' );
		$institution_name  = '';
		if ( ! is_wp_error( $institutions ) && ! empty( $institutions ) ) {
			$institution_name = strtolower( trim( $institutions[0]->name ) );
		}

		$published_date = get_post_meta( $post_item->ID, 'published_date', true );
		$published_key  = strtolower( trim( (string) $published_date ) );

		$semantic_key       = 'sem:' . $institution_name . '|' . $title_norm . '|' . $published_key;
		$semantic_key_loose = 'sem-loose:' . $institution_name . '|' . $title_norm;
		$key          = ! empty( $key_source ) ? 'source:' . $key_source : $semantic_key;

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		if ( isset( $seen[ $semantic_key ] ) || isset( $seen[ $semantic_key_loose ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$seen[ $semantic_key ]       = true;
		$seen[ $semantic_key_loose ] = true;
		$unique[]     = $post_item;
	}

	return $unique;
}

/**
 * Sort jobs: active first (nearest deadline first), expired second.
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

	$deadline_a = ! empty( $state_a['deadline'] ) ? strtotime( $state_a['deadline'] ) : false;
	$deadline_b = ! empty( $state_b['deadline'] ) ? strtotime( $state_b['deadline'] ) : false;

	if ( 0 === $group_a ) {
		$deadline_a = false === $deadline_a ? PHP_INT_MAX : $deadline_a;
		$deadline_b = false === $deadline_b ? PHP_INT_MAX : $deadline_b;
		if ( $deadline_a !== $deadline_b ) {
			return $deadline_a - $deadline_b;
		}
	} else {
		$deadline_a = false === $deadline_a ? 0 : $deadline_a;
		$deadline_b = false === $deadline_b ? 0 : $deadline_b;
		if ( $deadline_a !== $deadline_b ) {
			return $deadline_b - $deadline_a;
		}
	}

	$date_a = strtotime( $a->post_date_gmt );
	$date_b = strtotime( $b->post_date_gmt );
	return $date_b - $date_a;
}

/**
 * Read and sanitize status filter.
 *
 * @return string
 */
function decriptat_pj_get_status_filter() {
	$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'active';
	if ( ! in_array( $status, array( 'active', 'expired', 'all' ), true ) ) {
		return 'active';
	}
	return $status;
}

/**
 * Whether job state should be visible for a status filter.
 *
 * @param array<string, mixed> $state Job state.
 * @param string               $status_filter Filter mode.
 * @return bool
 */
function decriptat_pj_job_matches_status( $state, $status_filter ) {
	if ( 'expired' === $status_filter ) {
		return ! empty( $state['is_expired'] );
	}
	if ( 'all' === $status_filter ) {
		return true;
	}
	return empty( $state['is_expired'] );
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

	$selected_category = '';
	if ( isset( $_GET['job_category'] ) ) {
		$selected_category = sanitize_title( wp_unslash( $_GET['job_category'] ) );
	}
	$status_filter = decriptat_pj_get_status_filter();

	if ( ! empty( $selected_category ) ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'job_category',
				'field'    => 'slug',
				'terms'    => array( $selected_category ),
			),
		);
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
	$posts = decriptat_pj_unique_jobs( decriptat_pj_unique_posts( $query->posts ) );
	$category_terms = get_terms(
		array(
			'taxonomy'   => 'job_category',
			'hide_empty' => true,
		)
	);

	$posts = array_values(
		array_filter(
			$posts,
			function ( $job_post ) use ( $status_filter ) {
				$state = decriptat_pj_get_job_state( $job_post->ID );
				return decriptat_pj_job_matches_status( $state, $status_filter );
			}
		)
	);

	usort( $posts, 'decriptat_pj_sort_jobs' );

	ob_start();
	?>
	<div class="decriptat-pj-shell decriptat-pj-shortcode-list">
		<form class="decriptat-pj-filter-form decriptat-pj-shortcode-filter" method="get">
			<div class="decriptat-pj-filter-field decriptat-pj-filter-search">
				<label for="decriptat-pj-shortcode-q"><?php esc_html_e( 'Cauta', 'decriptat-public-jobs' ); ?></label>
				<input id="decriptat-pj-shortcode-q" type="search" name="q" placeholder="<?php esc_attr_e( 'Titlu, cuvinte cheie...', 'decriptat-public-jobs' ); ?>" value="<?php echo esc_attr( $search_query ); ?>" />
			</div>
			<div class="decriptat-pj-filter-field">
				<label for="decriptat-pj-shortcode-category"><?php esc_html_e( 'Categorie', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-shortcode-category" name="job_category">
					<option value=""><?php esc_html_e( 'Toate', 'decriptat-public-jobs' ); ?></option>
					<?php if ( ! is_wp_error( $category_terms ) ) : ?>
						<?php foreach ( $category_terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected_category, $term->slug ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>
			<div class="decriptat-pj-filter-field">
				<label for="decriptat-pj-shortcode-status"><?php esc_html_e( 'Status', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-shortcode-status" name="status">
					<option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'decriptat-public-jobs' ); ?></option>
					<option value="expired" <?php selected( $status_filter, 'expired' ); ?>><?php esc_html_e( 'Expirate', 'decriptat-public-jobs' ); ?></option>
					<option value="all" <?php selected( $status_filter, 'all' ); ?>><?php esc_html_e( 'Toate', 'decriptat-public-jobs' ); ?></option>
				</select>
			</div>
			<div class="decriptat-pj-filter-actions">
				<button type="submit" class="decriptat-pj-btn"><?php esc_html_e( 'Filtreaza', 'decriptat-public-jobs' ); ?></button>
			</div>
		</form>
		<?php if ( ! empty( $posts ) ) : ?>
			<div class="decriptat-pj-job-grid decriptat-pj-job-grid-shortcode">
				<?php
				foreach ( $posts as $job_post ) :
					$location     = get_post_meta( $job_post->ID, 'location', true );
					$published    = get_post_meta( $job_post->ID, 'published_date', true );
					$state        = decriptat_pj_get_job_state( $job_post->ID );
					$institutions = get_the_terms( $job_post->ID, 'institution' );
					$categories   = get_the_terms( $job_post->ID, 'job_category' );
					$primary_category = '';
					if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
						$primary_category = $categories[0]->name;
					}
					$institution  = '';
					if ( ! is_wp_error( $institutions ) && ! empty( $institutions ) ) {
						$institution = $institutions[0]->name;
					}
					$item_class = $state['is_expired'] ? 'decriptat-pj-job-card is-expired' : 'decriptat-pj-job-card';
					?>
					<article class="<?php echo esc_attr( $item_class ); ?>">
						<div class="decriptat-pj-card-top">
							<?php if ( ! empty( $state['label'] ) ) : ?>
								<span class="decriptat-pj-status-badge <?php echo $state['is_expired'] ? 'is-expired' : 'is-active'; ?>">
									<?php echo esc_html( $state['label'] ); ?>
								</span>
							<?php endif; ?>
						</div>

						<h3 class="decriptat-pj-card-title">
							<a href="<?php echo esc_url( get_permalink( $job_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $job_post->ID ) ); ?></a>
						</h3>

						<div class="decriptat-pj-meta-chips">
							<?php if ( ! empty( $institution ) ) : ?>
								<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $institution ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $state['deadline'] ) ) : ?>
								<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( sprintf( __( 'Termen: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $state['deadline'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $location ) ) : ?>
								<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $location ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( ! empty( $primary_category ) ) : ?>
							<div class="decriptat-pj-category-badges">
								<span class="decriptat-pj-category-badge"><?php echo esc_html( $primary_category ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $published ) ) : ?>
							<div class="decriptat-pj-card-footer">
								<span class="decriptat-pj-published"><?php echo esc_html( sprintf( __( 'Publicat: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $published ) ) ); ?></span>
							</div>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
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
