<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<main id="primary" class="site-main decriptat-pj-archive">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Joburi in sectorul public', 'decriptat-public-jobs' ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="decriptat-pj-job-list">
			<?php
			while ( have_posts() ) :
				the_post();
				$source_url     = get_post_meta( get_the_ID(), 'source_url', true );
				$published_date = get_post_meta( get_the_ID(), 'published_date', true );
				$deadline       = get_post_meta( get_the_ID(), 'deadline', true );
				$location       = get_post_meta( get_the_ID(), 'location', true );
				$is_it          = (bool) get_post_meta( get_the_ID(), 'is_it', true );
				?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'decriptat-pj-job-card' ); ?>>
					<h2 class="entry-title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h2>
					<div class="entry-excerpt">
						<?php the_excerpt(); ?>
					</div>
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
					<p>
						<a href="<?php the_permalink(); ?>"><?php esc_html_e( 'Vezi detalii', 'decriptat-public-jobs' ); ?></a>
						<?php if ( ! empty( $source_url ) ) : ?>
							| <a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Sursa oficiala', 'decriptat-public-jobs' ); ?></a>
						<?php endif; ?>
					</p>
				</article>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<section class="decriptat-pj-empty-state">
			<h2><?php esc_html_e( 'Momentan nu exista joburi publicate.', 'decriptat-public-jobs' ); ?></h2>
			<p><?php esc_html_e( 'Revino in curand pentru noi anunturi.', 'decriptat-public-jobs' ); ?></p>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
