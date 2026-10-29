<?php
/**
 * Overview tab: KPIs, provider health, attention list and recent activity.
 *
 * @var array<string, mixed> $rbw_view View data.
 *
 * @package RateBridge
 */

use RateBridge\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$rbw_overview = isset( $rbw_view['overview'] ) && is_array( $rbw_view['overview'] ) ? $rbw_view['overview'] : array();
$rbw_kpis     = isset( $rbw_overview['kpis'] ) ? (array) $rbw_overview['kpis'] : array();
$rbw_provs    = isset( $rbw_overview['providers'] ) ? (array) $rbw_overview['providers'] : array();
$rbw_attention = isset( $rbw_overview['attention'] ) ? (array) $rbw_overview['attention'] : array();
$rbw_activity = isset( $rbw_overview['activity'] ) ? (array) $rbw_overview['activity'] : array();
$rbw_is_empty = ! empty( $rbw_overview['empty'] );

$rbw_tab_link = static function ( string $tab ): string {
	return add_query_arg(
		array(
			'page' => SettingsPage::SLUG,
			'tab'  => $tab,
		),
		admin_url( 'admin.php' )
	);
};
?>

<?php if ( $rbw_is_empty ) : ?>
	<div class="rbw-empty-state">
		<svg class="rbw-icon rbw-empty-icon" aria-hidden="true"><use href="#rbw-i-coin"></use></svg>
		<h2><?php esc_html_e( 'Add your first currency', 'ratebridge-woo' ); ?></h2>
		<p>
			<?php esc_html_e( 'Prices can only be calculated once at least one currency has a rate. Add a currency, pick a price source and enable it.', 'ratebridge-woo' ); ?>
		</p>
		<a class="button button-primary" href="<?php echo esc_url( $rbw_tab_link( 'currencies' ) ); ?>">
			<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-plus"></use></svg>
			<?php esc_html_e( 'Add currency', 'ratebridge-woo' ); ?>
		</a>
	</div>
<?php else : ?>

	<!-- KPIs -->
	<div class="rbw-kpis">
		<div class="rbw-kpi">
			<span class="rbw-kpi-label"><?php esc_html_e( 'Active currencies', 'ratebridge-woo' ); ?></span>
			<strong class="rbw-kpi-value"><?php echo esc_html( (string) ( $rbw_kpis['active_currencies'] ?? 0 ) ); ?></strong>
			<span class="rbw-kpi-meta">
				<?php
				printf(
					/* translators: %d: total currencies */
					esc_html__( 'of %d configured', 'ratebridge-woo' ),
					(int) ( $rbw_kpis['total_currencies'] ?? 0 )
				);
				?>
			</span>
		</div>

		<div class="rbw-kpi">
			<span class="rbw-kpi-label"><?php esc_html_e( 'Last successful update', 'ratebridge-woo' ); ?></span>
			<strong class="rbw-kpi-value rbw-kpi-text">
				<?php echo esc_html( (string) ( $rbw_kpis['last_update']['label'] ?? '—' ) ); ?>
			</strong>
			<span class="rbw-kpi-meta">
				<?php
				$rbw_last_ts = (int) ( $rbw_kpis['last_update']['timestamp'] ?? 0 );
				echo $rbw_last_ts > 0
					? \RateBridge\Frontend\Bidi::isolate( \RateBridge\Core\DateFormatter::format( $rbw_last_ts, 'Y-m-d H:i' ) )
					: esc_html__( 'no update yet', 'ratebridge-woo' );
				?>
			</span>
		</div>

		<div class="rbw-kpi">
			<span class="rbw-kpi-label"><?php esc_html_e( 'Managed products', 'ratebridge-woo' ); ?></span>
			<strong class="rbw-kpi-value"><?php echo esc_html( (string) ( $rbw_kpis['products'] ?? 0 ) ); ?></strong>
			<span class="rbw-kpi-meta">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
					<?php esc_html_e( 'View products', 'ratebridge-woo' ); ?>
				</a>
			</span>
		</div>

		<div class="rbw-kpi">
			<span class="rbw-kpi-label"><?php esc_html_e( 'Next scheduled run', 'ratebridge-woo' ); ?></span>
			<strong class="rbw-kpi-value rbw-kpi-text">
				<?php
				$rbw_next = (int) ( $rbw_kpis['next_run'] ?? 0 );
				echo $rbw_next > 0
					? \RateBridge\Frontend\Bidi::isolate( \RateBridge\Core\DateFormatter::format( $rbw_next, 'H:i' ) )
					: esc_html__( 'not scheduled', 'ratebridge-woo' );
				?>
			</strong>
			<span class="rbw-kpi-meta">
				<?php
				echo esc_html(
					$rbw_next > 0
						? sprintf(
							/* translators: %s: relative time */
							__( 'in %s', 'ratebridge-woo' ),
							\RateBridge\Frontend\Frontend::time_ago( time() + max( 0, $rbw_next - time() ) )
						)
						: __( 'WP-Cron is not scheduled', 'ratebridge-woo' )
				);
				?>
			</span>
		</div>
	</div>

	<div class="rbw-grid-2">
		<!-- Attention -->
		<section class="rbw-card" aria-labelledby="rbw-attention-title">
			<header class="rbw-card-head">
				<h2 id="rbw-attention-title">
					<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-warning"></use></svg>
					<?php esc_html_e( 'Needs attention', 'ratebridge-woo' ); ?>
				</h2>
				<?php if ( ! empty( $rbw_attention ) ) : ?>
					<span class="rbw-badge is-warning"><?php echo esc_html( (string) count( $rbw_attention ) ); ?></span>
				<?php endif; ?>
			</header>

			<?php if ( empty( $rbw_attention ) ) : ?>
				<p class="rbw-empty-inline">
					<svg class="rbw-icon is-success" aria-hidden="true"><use href="#rbw-i-check"></use></svg>
					<?php esc_html_e( 'Nothing needs your attention right now.', 'ratebridge-woo' ); ?>
				</p>
			<?php else : ?>
				<ul class="rbw-attention">
					<?php foreach ( $rbw_attention as $rbw_row ) : ?>
						<li class="rbw-attention-item is-<?php echo esc_attr( (string) $rbw_row['level'] ); ?>">
							<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-<?php echo esc_attr( 'error' === $rbw_row['level'] ? 'error' : 'warning' ); ?>"></use></svg>
							<div class="rbw-attention-body">
								<strong><?php echo esc_html( (string) $rbw_row['title'] ); ?></strong>
								<span><?php echo esc_html( (string) $rbw_row['text'] ); ?></span>
							</div>
							<a class="rbw-attention-action" href="<?php echo esc_url( $rbw_tab_link( (string) $rbw_row['tab'] ) ); ?>">
								<?php echo esc_html( (string) $rbw_row['action'] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- Providers -->
		<section class="rbw-card" aria-labelledby="rbw-providers-title">
			<header class="rbw-card-head">
				<h2 id="rbw-providers-title">
					<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-link"></use></svg>
					<?php esc_html_e( 'Price sources', 'ratebridge-woo' ); ?>
				</h2>
				<a class="rbw-card-link" href="<?php echo esc_url( $rbw_tab_link( 'sources' ) ); ?>">
					<?php esc_html_e( 'Manage', 'ratebridge-woo' ); ?>
				</a>
			</header>

			<ul class="rbw-provider-list">
				<?php foreach ( $rbw_provs as $rbw_provider ) : ?>
					<li class="rbw-provider-row">
						<div class="rbw-provider-main">
							<strong><?php echo esc_html( (string) $rbw_provider['label'] ); ?></strong>
							<span class="rbw-badge is-<?php echo esc_attr( $rbw_provider['status'] ); ?>">
								<?php echo esc_html( (string) $rbw_provider['status_text'] ); ?>
							</span>
						</div>

						<?php if ( $rbw_provider['uses_network'] ) : ?>
							<div class="rbw-meter-wrap">
								<div
									class="rbw-meter"
									role="progressbar"
									aria-valuemin="0"
									aria-valuemax="100"
									aria-valuenow="<?php echo esc_attr( (string) $rbw_provider['percent'] ); ?>"
									aria-label="<?php esc_attr_e( 'Monthly request quota used', 'ratebridge-woo' ); ?>"
								>
									<span class="rbw-meter-fill<?php echo $rbw_provider['percent'] >= 90 ? ' is-warn' : ''; ?>" style="inline-size: <?php echo esc_attr( (string) $rbw_provider['percent'] ); ?>%"></span>
								</div>
								<span class="rbw-meter-label">
									<?php
									echo esc_html(
										$rbw_provider['limit'] > 0
											? sprintf(
												/* translators: 1: used, 2: limit */
												__( '%1$d of %2$d requests', 'ratebridge-woo' ),
												(int) $rbw_provider['used'],
												(int) $rbw_provider['limit']
											)
											: sprintf(
												/* translators: %d: used requests */
												__( '%d requests', 'ratebridge-woo' ),
												(int) $rbw_provider['used']
											)
									);
									?>
								</span>
							</div>
						<?php endif; ?>

						<?php if ( '' !== (string) $rbw_provider['next_allowed'] ) : ?>
							<p class="rbw-provider-meta">
								<?php
								printf(
									/* translators: %s: date and time, isolated so its digits keep order in RTL */
									esc_html__( 'Next request allowed: %s', 'ratebridge-woo' ),
									\RateBridge\Frontend\Bidi::isolate( (string) $rbw_provider['next_allowed'] )
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( '' !== (string) $rbw_provider['last_error'] ) : ?>
							<p class="rbw-provider-error"><?php echo esc_html( (string) $rbw_provider['last_error'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>

	<!-- Activity -->
	<section class="rbw-card" aria-labelledby="rbw-activity-title">
		<header class="rbw-card-head">
			<h2 id="rbw-activity-title">
				<svg class="rbw-icon" aria-hidden="true"><use href="#rbw-i-clock"></use></svg>
				<?php esc_html_e( 'Recent activity', 'ratebridge-woo' ); ?>
			</h2>
			<a class="rbw-card-link" href="<?php echo esc_url( $rbw_tab_link( 'currencies' ) ); ?>">
				<?php esc_html_e( 'Full log', 'ratebridge-woo' ); ?>
			</a>
		</header>

		<?php if ( empty( $rbw_activity ) ) : ?>
			<p class="rbw-empty-inline rbw-muted"><?php esc_html_e( 'No update attempts recorded yet.', 'ratebridge-woo' ); ?></p>
		<?php else : ?>
			<ol class="rbw-timeline">
				<?php foreach ( $rbw_activity as $rbw_entry ) : ?>
					<?php
					$rbw_ok      = 'success' === ( $rbw_entry['status'] ?? '' );
					$rbw_time    = (int) ( $rbw_entry['time'] ?? 0 );
					$rbw_message = trim( (string) ( $rbw_entry['message'] ?? '' ) );
					$rbw_long    = mb_strlen( $rbw_message ) > 90;
					?>
					<li class="rbw-timeline-item is-<?php echo esc_attr( $rbw_ok ? 'ok' : 'error' ); ?>">
						<span class="rbw-timeline-dot" aria-hidden="true"></span>

						<div class="rbw-timeline-body">
							<div class="rbw-timeline-meta">
								<time
									class="rbw-timeline-time"
									datetime="<?php echo esc_attr( gmdate( 'c', $rbw_time ) ); ?>"
								>
									<?php
									// Isolated so `2026-10-29 17:23` keeps its order inside RTL text.
									echo \RateBridge\Frontend\Bidi::isolate( \RateBridge\Core\DateFormatter::format( $rbw_time, 'Y-m-d H:i' ) );
									?>
								</time>
								<span class="rbw-badge is-<?php echo esc_attr( $rbw_ok ? 'success' : 'error' ); ?>">
									<?php echo esc_html( $rbw_ok ? __( 'Success', 'ratebridge-woo' ) : __( 'Error', 'ratebridge-woo' ) ); ?>
								</span>
								<span class="rbw-muted"><?php echo esc_html( 'manual' === ( $rbw_entry['trigger'] ?? '' ) ? __( 'Manual', 'ratebridge-woo' ) : __( 'Cron', 'ratebridge-woo' ) ); ?></span>
							</div>

							<?php if ( $rbw_long ) : ?>
								<details class="rbw-timeline-details">
									<summary class="rbw-timeline-summary">
										<span class="rbw-timeline-clamp"><?php echo esc_html( $rbw_message ); ?></span>
										<span class="rbw-expand-text"><?php esc_html_e( 'More', 'ratebridge-woo' ); ?></span>
										<span class="rbw-collapse-text"><?php esc_html_e( 'Less', 'ratebridge-woo' ); ?></span>
									</summary>
									<p class="rbw-timeline-full"><?php echo esc_html( $rbw_message ); ?></p>
								</details>
							<?php else : ?>
								<p class="rbw-timeline-message"><?php echo esc_html( $rbw_message ); ?></p>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</section>

<?php endif; ?>
