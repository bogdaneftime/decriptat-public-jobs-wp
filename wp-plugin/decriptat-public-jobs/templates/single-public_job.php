<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main decriptat-pj-single">
	<?php if ( have_posts() ) : ?>
		<?php
		while ( have_posts() ) :
			the_post();
			$source_url     = get_post_meta( get_the_ID(), 'source_url', true );
			$published_date = get_post_meta( get_the_ID(), 'published_date', true );
			$deadline       = get_post_meta( get_the_ID(), 'deadline', true );
			$location       = get_post_meta( get_the_ID(), 'location', true );
			$is_it          = (bool) get_post_meta( get_the_ID(), 'is_it', true );
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'decriptat-pj-job-single' ); ?>>
				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>

				<ul class="decriptat-pj-meta">
					<?php if ( ! empty( $published_date ) ) : ?>
						<li><strong><?php esc_html_e( 'Publicat:', 'decriptat-public-jobs' ); ?></strong> <?php echo esc_html( $published_date ); ?></li>
					<?php endif; ?>
					<?php if ( ! empty( $deadline ) ) : ?>
						<li><strong><?php esc_html_e( 'Termen:', 'decriptat-public-jobs' ); ?></strong> <?php echo esc_html( $deadline ); ?></li>
					<?php endif; ?>
					<?php if ( ! empty( $location ) ) : ?>
						<li><strong><?php esc_html_e( 'Locatie:', 'decriptat-public-jobs' ); ?></strong> <?php echo esc_html( $location ); ?></li>
					<?php endif; ?>
					<li><strong><?php esc_html_e( 'IT:', 'decriptat-public-jobs' ); ?></strong> <?php echo $is_it ? esc_html__( 'Da', 'decriptat-public-jobs' ) : esc_html__( 'Nu', 'decriptat-public-jobs' ); ?></li>
				</ul>

				<div class="entry-content">
					<?php the_content(); ?>
				</div>

				<?php if ( ! empty( $source_url ) ) : ?>
					<p>
						<a href="<?php echo esc_url( $source_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Vezi anuntul oficial', 'decriptat-public-jobs' ); ?>
						</a>
					</p>
				<?php endif; ?>
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
