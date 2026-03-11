<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$search_query       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$selected_category  = isset( $_GET['job_category'] ) ? sanitize_title( wp_unslash( $_GET['job_category'] ) ) : '';
$selected_institute = isset( $_GET['institution'] ) ? sanitize_title( wp_unslash( $_GET['institution'] ) ) : '';
$selected_status    = function_exists( 'decriptat_pj_get_status_filter' ) ? decriptat_pj_get_status_filter() : 'active';
$category_terms     = get_terms(
	array(
		'taxonomy'   => 'job_category',
		'hide_empty' => true,
	)
);
$institution_terms  = get_terms(
	array(
		'taxonomy'   => 'institution',
		'hide_empty' => true,
	)
);
?>

<main id="primary" class="site-main decriptat-pj-shell decriptat-pj-archive">
	<header class="decriptat-pj-page-header">
		<h1 class="decriptat-pj-page-title"><?php esc_html_e( 'Joburi in sectorul public', 'decriptat-public-jobs' ); ?></h1>
		<p class="decriptat-pj-page-intro"><?php esc_html_e( 'Anunturi verificate, prezentate clar pentru aplicare rapida.', 'decriptat-public-jobs' ); ?></p>
	</header>

	<section class="decriptat-pj-filter-block" aria-label="<?php esc_attr_e( 'Cautare si filtre', 'decriptat-public-jobs' ); ?>">
		<form method="get" class="decriptat-pj-filter-form">
			<div class="decriptat-pj-filter-field decriptat-pj-filter-search">
				<label for="decriptat-pj-q"><?php esc_html_e( 'Cauta', 'decriptat-public-jobs' ); ?></label>
				<input
					id="decriptat-pj-q"
					type="search"
					name="q"
					placeholder="<?php esc_attr_e( 'Titlu, cuvinte cheie...', 'decriptat-public-jobs' ); ?>"
					value="<?php echo esc_attr( $search_query ); ?>"
				/>
			</div>

			<div class="decriptat-pj-filter-field">
				<label for="decriptat-pj-job-category"><?php esc_html_e( 'Categorie', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-job-category" name="job_category">
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
				<label for="decriptat-pj-institution"><?php esc_html_e( 'Institutie', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-institution" name="institution">
					<option value=""><?php esc_html_e( 'Toate', 'decriptat-public-jobs' ); ?></option>
					<?php if ( ! is_wp_error( $institution_terms ) ) : ?>
						<?php foreach ( $institution_terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $selected_institute, $term->slug ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div class="decriptat-pj-filter-field">
				<label for="decriptat-pj-status"><?php esc_html_e( 'Status', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-status" name="status">
					<option value="active" <?php selected( $selected_status, 'active' ); ?>><?php esc_html_e( 'Active', 'decriptat-public-jobs' ); ?></option>
					<option value="expired" <?php selected( $selected_status, 'expired' ); ?>><?php esc_html_e( 'Expirate', 'decriptat-public-jobs' ); ?></option>
					<option value="all" <?php selected( $selected_status, 'all' ); ?>><?php esc_html_e( 'Toate', 'decriptat-public-jobs' ); ?></option>
				</select>
			</div>

			<div class="decriptat-pj-filter-actions">
				<button type="submit" class="decriptat-pj-btn"><?php esc_html_e( 'Filtreaza', 'decriptat-public-jobs' ); ?></button>
				<a class="decriptat-pj-reset" href="<?php echo esc_url( get_post_type_archive_link( 'public_job' ) ); ?>">
					<?php esc_html_e( 'Reseteaza', 'decriptat-public-jobs' ); ?>
				</a>
			</div>
		</form>
	</section>

	<?php if ( have_posts() ) : ?>
		<?php
		global $wp_query;
		$sorted_posts = $wp_query->posts;
		if ( function_exists( 'decriptat_pj_sort_jobs' ) ) {
			usort( $sorted_posts, 'decriptat_pj_sort_jobs' );
		}
		?>
		<div class="decriptat-pj-job-grid">
			<?php
			foreach ( $sorted_posts as $job_post ) :
				setup_postdata( $job_post );
				$post_id        = $job_post->ID;
				$source_url     = get_post_meta( $post_id, 'source_url', true );
				$deadline       = get_post_meta( $post_id, 'deadline', true );
				$location       = get_post_meta( $post_id, 'location', true );
				$published_date = get_post_meta( $post_id, 'published_date', true );
				$is_it          = (bool) get_post_meta( $post_id, 'is_it', true );
				$state          = function_exists( 'decriptat_pj_get_job_state' ) ? decriptat_pj_get_job_state( $post_id ) : array(
					'is_expired' => false,
					'label'      => '',
				);
				$job_categories = get_the_terms( $post_id, 'job_category' );
				$institutions   = get_the_terms( $post_id, 'institution' );
				$card_classes   = $state['is_expired'] ? 'decriptat-pj-job-card is-expired' : 'decriptat-pj-job-card';
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( $card_classes ); ?>>
					<div class="decriptat-pj-card-top">
						<?php if ( ! empty( $state['label'] ) ) : ?>
							<span class="decriptat-pj-status-badge <?php echo $state['is_expired'] ? 'is-expired' : 'is-active'; ?>">
								<?php echo esc_html( $state['label'] ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $is_it ) : ?>
							<span class="decriptat-pj-chip"><?php esc_html_e( 'IT', 'decriptat-public-jobs' ); ?></span>
						<?php endif; ?>
					</div>

					<h2 class="decriptat-pj-card-title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h2>

					<?php if ( has_excerpt() ) : ?>
						<div class="decriptat-pj-card-excerpt"><?php the_excerpt(); ?></div>
					<?php endif; ?>

					<div class="decriptat-pj-meta-chips">
						<?php if ( ! empty( $institutions ) && ! is_wp_error( $institutions ) ) : ?>
							<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $institutions[0]->name ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $location ) ) : ?>
							<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( $location ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $deadline ) ) : ?>
							<span class="decriptat-pj-chip decriptat-pj-chip-soft"><?php echo esc_html( sprintf( __( 'Termen: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $deadline ) ) ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $job_categories ) && ! is_wp_error( $job_categories ) ) : ?>
						<div class="decriptat-pj-category-badges">
							<?php foreach ( $job_categories as $category ) : ?>
								<span class="decriptat-pj-category-badge"><?php echo esc_html( $category->name ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<div class="decriptat-pj-card-footer">
						<?php if ( ! empty( $published_date ) ) : ?>
							<span class="decriptat-pj-published"><?php echo esc_html( sprintf( __( 'Publicat: %s', 'decriptat-public-jobs' ), decriptat_pj_format_date( $published_date ) ) ); ?></span>
						<?php endif; ?>
						<div class="decriptat-pj-card-links">
							<a href="<?php the_permalink(); ?>"><?php esc_html_e( 'Vezi detalii', 'decriptat-public-jobs' ); ?></a>
							<?php if ( ! empty( $source_url ) ) : ?>
								<a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Sursa oficiala', 'decriptat-public-jobs' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endforeach; ?>
			<?php wp_reset_postdata(); ?>
		</div>

		<div class="decriptat-pj-pagination">
			<?php the_posts_pagination(); ?>
		</div>
	<?php else : ?>
		<section class="decriptat-pj-empty-state">
			<h2><?php esc_html_e( 'Momentan nu exista joburi publicate.', 'decriptat-public-jobs' ); ?></h2>
			<p><?php esc_html_e( 'Revino in curand pentru noi anunturi.', 'decriptat-public-jobs' ); ?></p>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
