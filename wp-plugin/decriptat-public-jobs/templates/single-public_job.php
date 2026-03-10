<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main decriptat-pj-shell decriptat-pj-single">
	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			$post_id        = get_the_ID();
			$source_url     = get_post_meta( $post_id, 'source_url', true );
			$published_date = get_post_meta( $post_id, 'published_date', true );
			$deadline       = get_post_meta( $post_id, 'deadline', true );
			$location       = get_post_meta( $post_id, 'location', true );
			$is_it          = (bool) get_post_meta( $post_id, 'is_it', true );
			$state          = function_exists( 'decriptat_pj_get_job_state' ) ? decriptat_pj_get_job_state( $post_id ) : array(
				'is_expired' => false,
				'label'      => '',
			);
			$job_categories = get_the_terms( $post_id, 'job_category' );
			$institutions   = get_the_terms( $post_id, 'institution' );
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'decriptat-pj-job-single' ); ?>>
				<header class="decriptat-pj-single-header">
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

					<h1 class="decriptat-pj-single-title"><?php the_title(); ?></h1>

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
				</header>

				<?php if ( $state['is_expired'] ) : ?>
					<div class="decriptat-pj-expired-notice">
						<?php esc_html_e( 'Acest anunt este expirat. Verifica sursa oficiala pentru actualizari.', 'decriptat-public-jobs' ); ?>
					</div>
				<?php endif; ?>

				<div class="decriptat-pj-single-layout">
					<div class="decriptat-pj-single-content">
						<?php the_content(); ?>
					</div>

					<aside class="decriptat-pj-single-aside">
						<h2><?php esc_html_e( 'Detalii rapide', 'decriptat-public-jobs' ); ?></h2>
						<ul class="decriptat-pj-detail-list">
							<?php if ( ! empty( $published_date ) ) : ?>
								<li><span><?php esc_html_e( 'Publicat', 'decriptat-public-jobs' ); ?></span><strong><?php echo esc_html( decriptat_pj_format_date( $published_date ) ); ?></strong></li>
							<?php endif; ?>
							<?php if ( ! empty( $deadline ) ) : ?>
								<li><span><?php esc_html_e( 'Termen limita', 'decriptat-public-jobs' ); ?></span><strong><?php echo esc_html( decriptat_pj_format_date( $deadline ) ); ?></strong></li>
							<?php endif; ?>
							<?php if ( ! empty( $location ) ) : ?>
								<li><span><?php esc_html_e( 'Locatie', 'decriptat-public-jobs' ); ?></span><strong><?php echo esc_html( $location ); ?></strong></li>
							<?php endif; ?>
							<?php if ( ! empty( $institutions ) && ! is_wp_error( $institutions ) ) : ?>
								<li><span><?php esc_html_e( 'Institutie', 'decriptat-public-jobs' ); ?></span><strong><?php echo esc_html( $institutions[0]->name ); ?></strong></li>
							<?php endif; ?>
						</ul>

						<div class="decriptat-pj-single-actions">
							<?php if ( ! empty( $source_url ) ) : ?>
								<a href="<?php echo esc_url( $source_url ); ?>" class="decriptat-pj-btn" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Vezi anuntul oficial', 'decriptat-public-jobs' ); ?>
								</a>
							<?php endif; ?>
							<a href="<?php echo esc_url( get_post_type_archive_link( 'public_job' ) ); ?>" class="decriptat-pj-btn decriptat-pj-btn-secondary">
								<?php esc_html_e( 'Toate joburile', 'decriptat-public-jobs' ); ?>
							</a>
						</div>
					</aside>
				</div>
			</article>
		<?php endwhile; ?>
	<?php else : ?>
		<section class="decriptat-pj-empty-state">
			<h2><?php esc_html_e( 'Anuntul nu a fost gasit.', 'decriptat-public-jobs' ); ?></h2>
			<p><?php esc_html_e( 'Incearca sa revii la lista de joburi.', 'decriptat-public-jobs' ); ?></p>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
