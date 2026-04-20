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
 * Get the primary city name for a public job.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function decriptat_pj_get_job_city_name( $post_id ) {
	$city_terms = get_the_terms( $post_id, 'job_city' );
	if ( ! is_wp_error( $city_terms ) && ! empty( $city_terms ) ) {
		return $city_terms[0]->name;
	}

	return '';
}

/**
 * Format a compact relative label for publication time.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function decriptat_pj_get_job_freshness_label( $post_id ) {
	$published_timestamp = decriptat_pj_parse_job_timestamp( get_post_meta( $post_id, 'published_date', true ) );
	if ( false === $published_timestamp ) {
		return '';
	}

	$today_ts = strtotime( current_time( 'Y-m-d' ) );
	$days     = max( 0, (int) floor( ( $today_ts - $published_timestamp ) / DAY_IN_SECONDS ) );

	if ( 0 === $days ) {
		return __( 'Publicat astazi', 'decriptat-public-jobs' );
	}

	if ( 1 === $days ) {
		return __( 'Publicat ieri', 'decriptat-public-jobs' );
	}

	/* translators: %d number of days since publication. */
	return sprintf( __( 'Publicat acum %d zile', 'decriptat-public-jobs' ), $days );
}

/**
 * Build an urgency label for the job deadline.
 *
 * @param string $deadline_raw Raw deadline string.
 * @param array<string, mixed> $state Job state.
 * @return string
 */
function decriptat_pj_get_deadline_countdown_label( $deadline_raw, $state ) {
	$deadline_ts = decriptat_pj_parse_job_timestamp( $deadline_raw );
	if ( false === $deadline_ts ) {
		return '';
	}

	$today_ts  = strtotime( current_time( 'Y-m-d' ) );
	$days_left = (int) floor( ( $deadline_ts - $today_ts ) / DAY_IN_SECONDS );

	if ( ! empty( $state['is_expired'] ) ) {
		/* translators: %d number of days since expiry. */
		return sprintf( __( 'Expirat de %d zile', 'decriptat-public-jobs' ), abs( min( 0, $days_left ) ) );
	}

	if ( 0 === $days_left ) {
		return __( 'Expira astazi', 'decriptat-public-jobs' );
	}

	if ( 1 === $days_left ) {
		return __( 'Expira maine', 'decriptat-public-jobs' );
	}

	/* translators: %d number of days until deadline. */
	return sprintf( __( 'Expira in %d zile', 'decriptat-public-jobs' ), $days_left );
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
 * Collect aggregate stats for the jobs landing page.
 *
 * @param array<int, WP_Post> $posts Jobs list.
 * @return array<string, int>
 */
function decriptat_pj_get_jobs_overview_stats( $posts ) {
	$stats = array(
		'total'        => count( $posts ),
		'active'       => 0,
		'expired'      => 0,
		'institutions' => 0,
		'recent'       => 0,
	);
	$institutions = array();
	$today_ts     = strtotime( current_time( 'Y-m-d' ) );

	foreach ( $posts as $job_post ) {
		$state = decriptat_pj_get_job_state( $job_post->ID );
		if ( ! empty( $state['is_expired'] ) ) {
			$stats['expired']++;
		} else {
			$stats['active']++;
		}

		$published_timestamp = decriptat_pj_parse_job_timestamp( get_post_meta( $job_post->ID, 'published_date', true ) );
		if ( false !== $published_timestamp ) {
			$days_since_published = (int) floor( ( $today_ts - $published_timestamp ) / DAY_IN_SECONDS );
			if ( $days_since_published <= 30 ) {
				$stats['recent']++;
			}
		}

		$job_terms = get_the_terms( $job_post->ID, 'institution' );
		if ( is_wp_error( $job_terms ) || empty( $job_terms ) ) {
			continue;
		}

		foreach ( $job_terms as $term ) {
			$institutions[ $term->term_id ] = true;
		}
	}

	$stats['institutions'] = count( $institutions );

	return $stats;
}

/**
 * Collect top institutions with active roles.
 *
 * @param array<int, WP_Post> $posts Jobs list.
 * @param int                 $limit Max results.
 * @return array<int, array<string, mixed>>
 */
function decriptat_pj_get_top_institutions( $posts, $limit = 6 ) {
	$institution_counts = array();

	foreach ( $posts as $job_post ) {
		$state = decriptat_pj_get_job_state( $job_post->ID );
		if ( ! empty( $state['is_expired'] ) ) {
			continue;
		}

		$job_terms = get_the_terms( $job_post->ID, 'institution' );
		if ( is_wp_error( $job_terms ) || empty( $job_terms ) ) {
			continue;
		}

		foreach ( $job_terms as $term ) {
			if ( ! isset( $institution_counts[ $term->term_id ] ) ) {
				$institution_counts[ $term->term_id ] = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
					'count'   => 0,
				);
			}

			$institution_counts[ $term->term_id ]['count']++;
		}
	}

	usort(
		$institution_counts,
		function ( $left, $right ) {
			if ( $left['count'] === $right['count'] ) {
				return strcmp( $left['name'], $right['name'] );
			}

			return $right['count'] - $left['count'];
		}
	);

	return array_slice( $institution_counts, 0, $limit );
}

/**
 * Fetch filtered public jobs for archive/shortcode rendering.
 *
 * @param array<string, mixed> $args Query-like options.
 * @return array<int, WP_Post>
 */
function decriptat_pj_get_filtered_jobs( $args = array() ) {
	$query_args = array(
		'post_type'      => 'public_job',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	);

	if ( ! empty( $args['search_query'] ) ) {
		$query_args['s'] = $args['search_query'];
	}

	$tax_query = array();

	if ( ! empty( $args['selected_category'] ) ) {
		$tax_query[] = array(
			'taxonomy' => 'job_category',
			'field'    => 'slug',
			'terms'    => array( $args['selected_category'] ),
		);
	}

	if ( ! empty( $args['selected_institution'] ) ) {
		$tax_query[] = array(
			'taxonomy' => 'institution',
			'field'    => 'slug',
			'terms'    => array( $args['selected_institution'] ),
		);
	}

	if ( ! empty( $args['selected_city'] ) ) {
		$tax_query[] = array(
			'taxonomy' => 'job_city',
			'field'    => 'slug',
			'terms'    => array( $args['selected_city'] ),
		);
	}

	if ( ! empty( $tax_query ) ) {
		$query_args['tax_query'] = $tax_query;
	}

	if ( ! empty( $args['it_only'] ) ) {
		$query_args['meta_query'] = array(
			array(
				'key'     => 'is_it',
				'value'   => '1',
				'compare' => '=',
			),
		);
	}

	$query = new WP_Query( $query_args );
	$posts = decriptat_pj_unique_posts( $query->posts );
	$status_filter = ! empty( $args['status_filter'] ) ? $args['status_filter'] : 'active';

	$posts = array_values(
		array_filter(
			$posts,
			function ( $job_post ) use ( $status_filter ) {
				$state = decriptat_pj_get_job_state( $job_post->ID );
				return decriptat_pj_job_matches_status( $state, $status_filter );
			}
		)
	);

	$posts = decriptat_pj_unique_jobs( $posts );
	usort( $posts, 'decriptat_pj_sort_jobs' );

	wp_reset_postdata();

	return $posts;
}

/**
 * Render a single public job card.
 *
 * @param WP_Post $job_post Job post object.
 * @param array<string, mixed> $args Rendering options.
 * @return void
 */
function decriptat_pj_render_job_card( $job_post, $args = array() ) {
	$defaults = array(
		'show_excerpt' => true,
		'show_links'   => true,
	);
	$args           = wp_parse_args( $args, $defaults );

	$post_id          = $job_post->ID;
	$source_url       = get_post_meta( $post_id, 'source_url', true );
	$deadline         = get_post_meta( $post_id, 'deadline', true );
	$location         = get_post_meta( $post_id, 'location', true );
	$published_date   = get_post_meta( $post_id, 'published_date', true );
	$is_it            = (bool) get_post_meta( $post_id, 'is_it', true );
	$state            = decriptat_pj_get_job_state( $post_id );
	$freshness_label  = decriptat_pj_get_job_freshness_label( $post_id );
	$countdown_label  = decriptat_pj_get_deadline_countdown_label( $deadline, $state );
	$job_categories   = get_the_terms( $post_id, 'job_category' );
	$primary_category = '';
	$institutions     = get_the_terms( $post_id, 'institution' );
	$city_name        = decriptat_pj_get_job_city_name( $post_id );

	if ( ! empty( $job_categories ) && ! is_wp_error( $job_categories ) ) {
		$primary_category = $job_categories[0]->name;
	}

	$card_classes = $state['is_expired'] ? 'decriptat-pj-job-card is-expired' : 'decriptat-pj-job-card';
	?>
	<article id="post-<?php echo esc_attr( $post_id ); ?>" <?php post_class( $card_classes, $post_id ); ?>>
		<div class="decriptat-pj-card-top">
			<div class="decriptat-pj-card-badges">
				<?php if ( ! empty( $state['label'] ) ) : ?>
					<span class="decriptat-pj-status-badge <?php echo $state['is_expired'] ? 'is-expired' : 'is-active'; ?>">
						<?php echo esc_html( $state['label'] ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $is_it ) : ?>
					<span class="decriptat-pj-chip"><?php esc_html_e( 'IT', 'decriptat-public-jobs' ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $city_name ) ) : ?>
					<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $city_name ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $countdown_label ) ) : ?>
				<span class="decriptat-pj-deadline-pill <?php echo $state['is_expired'] ? 'is-expired' : 'is-active'; ?>">
					<?php echo esc_html( $countdown_label ); ?>
				</span>
			<?php endif; ?>
		</div>

		<h2 class="decriptat-pj-card-title">
			<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
		</h2>

		<?php if ( ! empty( $institutions ) && ! is_wp_error( $institutions ) ) : ?>
			<p class="decriptat-pj-card-institution"><?php echo esc_html( $institutions[0]->name ); ?></p>
		<?php endif; ?>

		<?php if ( $args['show_excerpt'] && has_excerpt( $post_id ) ) : ?>
			<div class="decriptat-pj-card-excerpt"><?php echo wp_kses_post( get_the_excerpt( $post_id ) ); ?></div>
		<?php endif; ?>

		<div class="decriptat-pj-meta-chips">
			<?php if ( ! empty( $location ) ) : ?>
				<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $location ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $deadline ) ) : ?>
				<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( sprintf( __( 'Termen: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $deadline ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $primary_category ) ) : ?>
				<span class="decriptat-pj-category-badge"><?php echo esc_html( $primary_category ); ?></span>
			<?php endif; ?>
		</div>

		<div class="decriptat-pj-card-footer">
			<div class="decriptat-pj-card-trust">
				<?php if ( ! empty( $freshness_label ) ) : ?>
					<span class="decriptat-pj-published"><?php echo esc_html( $freshness_label ); ?></span>
				<?php elseif ( ! empty( $published_date ) ) : ?>
					<span class="decriptat-pj-published"><?php echo esc_html( sprintf( __( 'Publicat: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $published_date ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $source_url ) ) : ?>
					<span class="decriptat-pj-source-note"><?php esc_html_e( 'Sursa oficiala', 'decriptat-public-jobs' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $args['show_links'] ) : ?>
				<div class="decriptat-pj-card-links">
					<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php esc_html_e( 'Vezi detalii', 'decriptat-public-jobs' ); ?></a>
					<?php if ( ! empty( $source_url ) ) : ?>
						<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Anunt oficial', 'decriptat-public-jobs' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</article>
	<?php
}

/**
 * Render a public jobs list.
 *
 * @param bool $it_only Whether to filter only IT jobs.
 * @return string
 */
function decriptat_pj_render_jobs_shortcode( $it_only = false ) {
	$search_query = '';
	if ( isset( $_GET['q'] ) ) {
		$search_query = sanitize_text_field( wp_unslash( $_GET['q'] ) );
	}

	$selected_category = '';
	if ( isset( $_GET['job_category'] ) ) {
		$selected_category = sanitize_title( wp_unslash( $_GET['job_category'] ) );
	}
	$selected_city = '';
	if ( isset( $_GET['job_city'] ) ) {
		$selected_city = sanitize_title( wp_unslash( $_GET['job_city'] ) );
	}
	$status_filter = decriptat_pj_get_status_filter();
	$posts = decriptat_pj_get_filtered_jobs(
		array(
			'search_query'      => $search_query,
			'selected_category' => $selected_category,
			'selected_city'     => $selected_city,
			'status_filter'     => $status_filter,
			'it_only'           => $it_only,
		)
	);
	$category_terms = get_terms(
		array(
			'taxonomy'   => 'job_category',
			'hide_empty' => true,
		)
	);
	$city_terms = get_terms(
		array(
			'taxonomy'   => 'job_city',
			'hide_empty' => true,
		)
	);

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
				<label for="decriptat-pj-shortcode-city"><?php esc_html_e( 'Oras', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-shortcode-city" name="job_city">
					<option value=""><?php esc_html_e( 'Toate', 'decriptat-public-jobs' ); ?></option>
					<?php if ( ! is_wp_error( $city_terms ) ) : ?>
						<?php foreach ( $city_terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected_city, $term->slug ); ?>>
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
					decriptat_pj_render_job_card(
						$job_post,
						array(
							'show_excerpt' => false,
							'show_links'   => false,
						)
					);
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<p><?php esc_html_e( 'Nu exista joburi disponibile momentan.', 'decriptat-public-jobs' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
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
