<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$search_query       = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$selected_category  = isset( $_GET['job_category'] ) ? sanitize_title( wp_unslash( $_GET['job_category'] ) ) : '';
$selected_institute = isset( $_GET['institution'] ) ? sanitize_title( wp_unslash( $_GET['institution'] ) ) : '';
$selected_city      = isset( $_GET['job_city'] ) ? sanitize_title( wp_unslash( $_GET['job_city'] ) ) : '';
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
$city_terms         = get_terms(
	array(
		'taxonomy'   => 'job_city',
		'hide_empty' => true,
	)
);
?>

<?php
$all_jobs = function_exists( 'decriptat_pj_get_filtered_jobs' )
	? decriptat_pj_get_filtered_jobs(
		array(
			'status_filter' => 'all',
		)
	)
	: array();
$overview_stats = function_exists( 'decriptat_pj_get_jobs_overview_stats' ) ? decriptat_pj_get_jobs_overview_stats( $all_jobs ) : array();
$top_institutions = function_exists( 'decriptat_pj_get_top_institutions' ) ? decriptat_pj_get_top_institutions( $all_jobs ) : array();
?>
<main id="primary" class="site-main decriptat-pj-shell decriptat-pj-archive">
	<header class="decriptat-pj-page-header">
		<div class="decriptat-pj-page-hero">
			<div class="decriptat-pj-page-copy">
				<p class="decriptat-pj-eyebrow"><?php esc_html_e( 'Monitorizare centralizata', 'decriptat-public-jobs' ); ?></p>
				<h1 class="decriptat-pj-page-title"><?php esc_html_e( 'Joburi in sectorul public', 'decriptat-public-jobs' ); ?></h1>
				<p class="decriptat-pj-page-intro"><?php esc_html_e( 'Anunturi oficiale, filtrate clar dupa institutie, oras si termen, ca sa vezi repede unde merita sa aplici.', 'decriptat-public-jobs' ); ?></p>
				<div class="decriptat-pj-trust-row">
					<span class="decriptat-pj-trust-pill"><?php esc_html_e( 'Surse oficiale', 'decriptat-public-jobs' ); ?></span>
					<span class="decriptat-pj-trust-pill"><?php esc_html_e( 'Actualizari recente', 'decriptat-public-jobs' ); ?></span>
					<span class="decriptat-pj-trust-pill"><?php esc_html_e( 'Filtre rapide', 'decriptat-public-jobs' ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $overview_stats ) ) : ?>
				<div class="decriptat-pj-kpi-grid" aria-label="<?php esc_attr_e( 'Statistici joburi', 'decriptat-public-jobs' ); ?>">
					<div class="decriptat-pj-kpi-card">
						<strong><?php echo esc_html( number_format_i18n( $overview_stats['active'] ) ); ?></strong>
						<span><?php esc_html_e( 'joburi active', 'decriptat-public-jobs' ); ?></span>
					</div>
					<div class="decriptat-pj-kpi-card">
						<strong><?php echo esc_html( number_format_i18n( $overview_stats['institutions'] ) ); ?></strong>
						<span><?php esc_html_e( 'institutii monitorizate', 'decriptat-public-jobs' ); ?></span>
					</div>
					<div class="decriptat-pj-kpi-card">
						<strong><?php echo esc_html( number_format_i18n( $overview_stats['recent'] ) ); ?></strong>
						<span><?php esc_html_e( 'publicate in ultimele 30 zile', 'decriptat-public-jobs' ); ?></span>
					</div>
					<div class="decriptat-pj-kpi-card">
						<strong><?php echo esc_html( number_format_i18n( $overview_stats['total'] ) ); ?></strong>
						<span><?php esc_html_e( 'anunturi in arhiva', 'decriptat-public-jobs' ); ?></span>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( ! empty( $top_institutions ) ) : ?>
		<section class="decriptat-pj-spotlight-block" aria-label="<?php esc_attr_e( 'Institutiile cu cele mai multe joburi active', 'decriptat-public-jobs' ); ?>">
			<div class="decriptat-pj-section-heading">
				<div>
					<p class="decriptat-pj-section-kicker"><?php esc_html_e( 'Institutiile active acum', 'decriptat-public-jobs' ); ?></p>
					<h2><?php esc_html_e( 'Unde se publica cel mai des', 'decriptat-public-jobs' ); ?></h2>
				</div>
				<p><?php esc_html_e( 'Selecteaza direct o institutie cu roluri active ca sa restrangi cautarea mai repede.', 'decriptat-public-jobs' ); ?></p>
			</div>
			<div class="decriptat-pj-institution-grid">
				<?php foreach ( $top_institutions as $institution ) : ?>
					<a class="decriptat-pj-institution-card" href="<?php echo esc_url( add_query_arg( 'institution', $institution['slug'], get_post_type_archive_link( 'public_job' ) ) ); ?>">
						<span class="decriptat-pj-institution-name"><?php echo esc_html( $institution['name'] ); ?></span>
						<span class="decriptat-pj-institution-count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d number of active jobs. */
									_n( '%d job activ', '%d joburi active', $institution['count'], 'decriptat-public-jobs' ),
									$institution['count']
								)
							);
							?>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endif; ?>

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
				<label for="decriptat-pj-job-city"><?php esc_html_e( 'Oras', 'decriptat-public-jobs' ); ?></label>
				<select id="decriptat-pj-job-city" name="job_city">
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

	<?php
	$sorted_posts = function_exists( 'decriptat_pj_get_filtered_jobs' )
		? decriptat_pj_get_filtered_jobs(
			array(
				'search_query'        => $search_query,
				'selected_category'   => $selected_category,
				'selected_institution' => $selected_institute,
				'selected_city'       => $selected_city,
				'status_filter'       => $selected_status,
			)
		)
		: array();
	?>
	<?php if ( ! empty( $sorted_posts ) ) : ?>
		<div class="decriptat-pj-job-grid">
			<?php
			foreach ( $sorted_posts as $job_post ) :
				decriptat_pj_render_job_card( $job_post );
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<section class="decriptat-pj-empty-state">
			<h2><?php esc_html_e( 'Nu exista joburi pentru filtrul selectat.', 'decriptat-public-jobs' ); ?></h2>
			<p><?php esc_html_e( 'Incearca alt status sau reseteaza filtrele.', 'decriptat-public-jobs' ); ?></p>
		</section>
	<?php endif; ?>
</main>

<?php
get_footer();
