<?php
/**
 * Admin controller for Compact IMG.
 *
 * @package CompactIMG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WMFC_Plugin {
	const NONCE_ACTION = 'wmfc_admin_action';
	const PAGE_SLUG    = 'compact-img-compress-convert-images-to-webpavif';

	/** @var WMFC_Plugin|null */
	private static $instance = null;

	/** @var WMFC_Converter */
	private $converter;

	/**
	 * Return the singleton instance.
	 *
	 * @return WMFC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->converter = new WMFC_Converter();

		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wmfc_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_wmfc_convert_batch', array( $this, 'ajax_convert_batch' ) );
		add_action( 'wp_ajax_wmfc_rewrite_batch', array( $this, 'ajax_rewrite_batch' ) );
		add_action( 'wp_ajax_wmfc_delete_batch', array( $this, 'ajax_delete_batch' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WMFC_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/** Add a direct link from the Plugins screen to the optimizer. */
	public function plugin_action_links( $links ) {
		$url  = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Optimize Images', 'compact-img-compress-convert-images-to-webpavif' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/** Add the Tools submenu page. */
	public function add_admin_page() {
		add_management_page(
			__( 'Compact IMG: Compress & Convert Images to WebP/AVIF', 'compact-img-compress-convert-images-to-webpavif' ),
			__( 'Compact IMG', 'compact-img-compress-convert-images-to-webpavif' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Load page-specific CSS and JavaScript.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wmfc-admin',
			WMFC_URL . 'assets/admin.css',
			array(),
			WMFC_VERSION
		);

		wp_enqueue_script(
			'wmfc-admin',
			WMFC_URL . 'assets/admin.js',
			array(),
			WMFC_VERSION,
			true
		);

		wp_localize_script(
			'wmfc-admin',
			'wmfcSettings',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'batch'        => 4,
				'rewriteBatch' => 1000,
				'i18n'         => array(
					'confirmConvert' => __( 'Start optimizing your images? Keep this page open until the process finishes. Make sure you have a current backup.', 'compact-img-compress-convert-images-to-webpavif' ),
					'confirmDelete'  => __( 'Permanently delete all saved original files? This cannot be undone. Make sure you have a backup.', 'compact-img-compress-convert-images-to-webpavif' ),
					'networkError'   => __( 'The request failed. Try again. Images that are already finished will be skipped.', 'compact-img-compress-convert-images-to-webpavif' ),
					'working'           => __( 'Working', 'compact-img-compress-convert-images-to-webpavif' ),
					'scanning'          => __( 'Scanning', 'compact-img-compress-convert-images-to-webpavif' ),
					'scanningDescription'=> __( 'Checking your images, saved originals, image links, and storage savings.', 'compact-img-compress-convert-images-to-webpavif' ),
					'ready'              => __( 'Ready', 'compact-img-compress-convert-images-to-webpavif' ),
					'complete'           => __( 'Complete', 'compact-img-compress-convert-images-to-webpavif' ),
					'error'              => __( 'Error', 'compact-img-compress-convert-images-to-webpavif' ),
					'optimizing'         => __( 'Optimizing images', 'compact-img-compress-convert-images-to-webpavif' ),
					'updatingUrls'       => __( 'Updating image links', 'compact-img-compress-convert-images-to-webpavif' ),
					'deleting'           => __( 'Deleting original files', 'compact-img-compress-convert-images-to-webpavif' ),
					'urlUpdatePending'   => __( 'Some image links still need to be updated.', 'compact-img-compress-convert-images-to-webpavif' ),
					/* translators: 1: processed attachment count, 2: total attachment count, 3: converted count, 4: skipped count, 5: error count. */
					'convertSummary'     => __( 'Processed %1$s of %2$s. Converted: %3$s, skipped: %4$s, errors: %5$s.', 'compact-img-compress-convert-images-to-webpavif' ),
					'stopped'            => __( 'Stopped safely after the current batch. Run optimization again to continue.', 'compact-img-compress-convert-images-to-webpavif' ),
					'conversionFinished' => __( 'Image optimization is complete. Compact IMG is now updating image links.', 'compact-img-compress-convert-images-to-webpavif' ),
					/* translators: 1: database stage name, 2: checked row count, 3: updated row count. */
					'urlUpdateSummary'   => __( 'Updating image links in %1$s. Items checked: %2$s, items updated: %3$s.', 'compact-img-compress-convert-images-to-webpavif' ),
					'urlUpdateFinished'  => __( 'All image links are updated. Your original files are still saved.', 'compact-img-compress-convert-images-to-webpavif' ),
					/* translators: 1: processed attachment count, 2: total attachment count, 3: deleted file count, 4: missing file count, 5: failed file count. */
					'cleanupSummary'     => __( 'Processed %1$s of %2$s attachments. Deleted: %3$s, already missing: %4$s, failed: %5$s.', 'compact-img-compress-convert-images-to-webpavif' ),
					'cleanupFinished'    => __( 'Original file cleanup is complete.', 'compact-img-compress-convert-images-to-webpavif' ),
				),
			)
		);
	}

	/** Render the conversion page. */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap wmfc-wrap">
			<header class="wmfc-header">
				<div class="wmfc-header-copy">
					<h1><?php esc_html_e( 'Compact IMG: Compress & Convert Images to WebP/AVIF', 'compact-img-compress-convert-images-to-webpavif' ); ?></h1>
					<p><?php esc_html_e( 'Make your images smaller and convert them to WebP, AVIF, or JPG.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p>
				</div>
			</header>

			<nav class="nav-tab-wrapper wmfc-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Plugin sections', 'compact-img-compress-convert-images-to-webpavif' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" id="wmfc-tab-optimize" role="tab" aria-selected="true" aria-controls="wmfc-panel-optimize" data-wmfc-tab="optimize"><?php esc_html_e( 'Optimize Images', 'compact-img-compress-convert-images-to-webpavif' ); ?></button>
				<button type="button" class="nav-tab" id="wmfc-tab-guide" role="tab" aria-selected="false" aria-controls="wmfc-panel-guide" data-wmfc-tab="guide"><?php esc_html_e( 'How to Use', 'compact-img-compress-convert-images-to-webpavif' ); ?></button>
			</nav>

			<section id="wmfc-panel-optimize" class="wmfc-tab-panel" role="tabpanel" aria-labelledby="wmfc-tab-optimize">
				<div class="notice notice-warning inline wmfc-backup-notice">
					<p><strong><?php esc_html_e( 'Back up your website first.', 'compact-img-compress-convert-images-to-webpavif' ); ?></strong> <?php esc_html_e( 'Compact IMG keeps the original files until you choose to delete them.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p>
				</div>

				<div class="wmfc-metrics" aria-label="<?php esc_attr_e( 'Optimization summary', 'compact-img-compress-convert-images-to-webpavif' ); ?>">
					<div class="wmfc-metric"><span><?php esc_html_e( 'Optimized images', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><strong id="wmfc-metric-count">0</strong></div>
					<div class="wmfc-metric"><span><?php esc_html_e( 'Original size', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><strong id="wmfc-metric-original">0 MB</strong></div>
					<div class="wmfc-metric"><span><?php esc_html_e( 'Optimized size', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><strong id="wmfc-metric-optimized">0 MB</strong></div>
					<div class="wmfc-metric wmfc-metric-saved"><span><?php esc_html_e( 'Space saved', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><strong id="wmfc-metric-saved">0 MB</strong><small id="wmfc-metric-percent">0%</small></div>
				</div>

				<div class="wmfc-grid">
					<div class="wmfc-card wmfc-settings-card">
						<div class="wmfc-card-heading"><span class="wmfc-step-number">1</span><div><h2><?php esc_html_e( 'Choose your settings', 'compact-img-compress-convert-images-to-webpavif' ); ?></h2><p><?php esc_html_e( 'Pick a file format and image quality.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></div>
						<div class="wmfc-setting-row">
							<label for="wmfc-target"><?php esc_html_e( 'File format', 'compact-img-compress-convert-images-to-webpavif' ); ?></label>
							<select id="wmfc-target">
								<option value="webp"><?php esc_html_e( 'WebP (recommended)', 'compact-img-compress-convert-images-to-webpavif' ); ?></option>
								<option value="avif"><?php esc_html_e( 'AVIF (smallest files when supported)', 'compact-img-compress-convert-images-to-webpavif' ); ?></option>
								<option value="jpeg"><?php esc_html_e( 'JPG (JPEG)', 'compact-img-compress-convert-images-to-webpavif' ); ?></option>
							</select>
						</div>
						<div class="wmfc-setting-row">
							<div class="wmfc-label-line"><label for="wmfc-quality"><?php esc_html_e( 'Image quality', 'compact-img-compress-convert-images-to-webpavif' ); ?></label><output id="wmfc-quality-value" for="wmfc-quality">82</output></div>
							<input id="wmfc-quality" type="range" min="40" max="100" value="82" step="1">
							<div class="wmfc-range-labels"><span><?php esc_html_e( 'Smaller files', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><span><?php esc_html_e( 'Higher quality', 'compact-img-compress-convert-images-to-webpavif' ); ?></span></div>
						</div>
						<p class="description"><?php esc_html_e( 'Images already in the selected format are skipped. Animated GIFs are not changed.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p>
						<div class="wmfc-actions">
							<button type="button" class="button button-secondary" id="wmfc-scan"><span class="dashicons dashicons-search wmfc-button-icon" aria-hidden="true"></span><span><?php esc_html_e( 'Scan Images', 'compact-img-compress-convert-images-to-webpavif' ); ?></span></button>
							<button type="button" class="button button-primary" id="wmfc-convert" disabled><span class="dashicons dashicons-format-image wmfc-button-icon" aria-hidden="true"></span><span><?php esc_html_e( 'Optimize Images', 'compact-img-compress-convert-images-to-webpavif' ); ?></span></button>
							<button type="button" class="button" id="wmfc-stop" hidden><?php esc_html_e( 'Stop safely', 'compact-img-compress-convert-images-to-webpavif' ); ?></button>
						</div>
					</div>

					<div class="wmfc-card wmfc-progress-card">
						<div class="wmfc-card-heading"><span class="wmfc-step-number">2</span><div><h2><?php esc_html_e( 'Track progress', 'compact-img-compress-convert-images-to-webpavif' ); ?></h2><p><?php esc_html_e( 'If the process stops, you can safely start it again.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></div>
						<div class="wmfc-status-line"><span id="wmfc-progress-state" class="wmfc-status-badge"><?php esc_html_e( 'Ready', 'compact-img-compress-convert-images-to-webpavif' ); ?></span><strong id="wmfc-progress-percent">0%</strong></div>
						<div class="wmfc-progress" id="wmfc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="wmfc-progress-bar"></span></div>
						<p id="wmfc-summary" aria-live="polite"><?php esc_html_e( 'Scanning will start automatically.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p>
						<p><button type="button" class="button" id="wmfc-rewrite" disabled><span class="dashicons dashicons-update wmfc-button-icon" aria-hidden="true"></span><span><?php esc_html_e( 'Resume Image Link Update', 'compact-img-compress-convert-images-to-webpavif' ); ?></span></button></p>
						<ul id="wmfc-log" class="wmfc-log" aria-live="polite"></ul>
					</div>
				</div>

				<div class="wmfc-card wmfc-cleanup-card">
					<div class="wmfc-card-heading"><span class="wmfc-step-number">3</span><div><h2><?php esc_html_e( 'Delete original files (optional)', 'compact-img-compress-convert-images-to-webpavif' ); ?></h2><p><?php esc_html_e( 'First check your website and make sure every image looks correct.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></div>
					<div class="wmfc-cleanup-action"><div><strong><?php esc_html_e( 'Permanent action', 'compact-img-compress-convert-images-to-webpavif' ); ?></strong><span><?php esc_html_e( 'Deleted originals can only be recovered from your backup.', 'compact-img-compress-convert-images-to-webpavif' ); ?></span></div><button type="button" class="button wmfc-delete-button" id="wmfc-delete" disabled><?php esc_html_e( 'Delete Retained Originals', 'compact-img-compress-convert-images-to-webpavif' ); ?></button></div>
				</div>
			</section>

			<section id="wmfc-panel-guide" class="wmfc-tab-panel" role="tabpanel" aria-labelledby="wmfc-tab-guide" hidden>
				<div class="wmfc-guide-intro"><h2><?php esc_html_e( 'How to optimize your images', 'compact-img-compress-convert-images-to-webpavif' ); ?></h2><p><?php esc_html_e( 'Follow these steps in order. Your original files will stay safe unless you choose to delete them.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div>
				<ol class="wmfc-guide-steps">
					<li><span>1</span><div><h3><?php esc_html_e( 'Back up your website', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Save a copy of your WordPress database and uploads folder.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>2</span><div><h3><?php esc_html_e( 'Choose a format and quality', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'WebP works well for most sites. AVIF can create smaller files when your server supports it. Quality 82 is a good starting point.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>3</span><div><h3><?php esc_html_e( 'Scan your images', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Compact IMG counts the images it can optimize and shows your current storage savings.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>4</span><div><h3><?php esc_html_e( 'Start optimization', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Keep this page open while Compact IMG converts each image and its thumbnail sizes. You can stop safely between batches.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>5</span><div><h3><?php esc_html_e( 'Wait for image links to update', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Compact IMG updates old image links after conversion. If this step stops, use Resume Image Link Update.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>6</span><div><h3><?php esc_html_e( 'Check your website', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Open important pages, galleries, featured images, and the Media Library. Make sure every image looks correct.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
					<li><span>7</span><div><h3><?php esc_html_e( 'Delete originals if you want', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'This step is optional and permanent. Keep the originals unless you have checked your website and have a backup.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div></li>
				</ol>
				<div class="wmfc-guide-grid">
					<div class="wmfc-card"><h3><?php esc_html_e( 'Which images are changed?', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Compact IMG changes images in the Media Library and their WordPress thumbnail sizes. It does not change SVG files or images outside the Media Library.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div>
					<div class="wmfc-card"><h3><?php esc_html_e( 'How are originals protected?', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'An image switches to the new files only after all available sizes convert successfully. Original files stay on the server until optional cleanup.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div>
					<div class="wmfc-card"><h3><?php esc_html_e( 'Can I continue later?', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Yes. Finished images are skipped, and image link updates continue from the last saved position.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div>
					<div class="wmfc-card"><h3><?php esc_html_e( 'What should I know?', 'compact-img-compress-convert-images-to-webpavif' ); ?></h3><p><?php esc_html_e( 'Animated GIFs are skipped. JPG cannot keep transparent backgrounds. WebP and AVIF availability depends on your server.', 'compact-img-compress-convert-images-to-webpavif' ); ?></p></div>
				</div>
			</section>

			<footer class="wmfc-footer"><?php esc_html_e( 'Created by', 'compact-img-compress-convert-images-to-webpavif' ); ?> <a href="<?php echo esc_url( 'https://compactimg.com/' ); ?>" target="_blank" rel="noopener noreferrer">CompactIMG.com</a></footer>
		</div>
		<?php
	}

	/** Return Media Library counts. */
	public function ajax_scan() {
		$this->authorize_ajax();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'webp';
		$target = $this->validate_target( $target );

		wp_send_json_success( $this->converter->get_library_counts( $target ) );
	}

	/** Convert one batch. */
	public function ajax_convert_batch() {
		$this->authorize_ajax();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$target  = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'webp';
		$target  = $this->validate_target( $target );
		$quality = isset( $_POST['quality'] ) ? absint( wp_unslash( $_POST['quality'] ) ) : 82;
		$last_id = isset( $_POST['lastId'] ) ? absint( wp_unslash( $_POST['lastId'] ) ) : 0;
		$batch   = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 2;
		$quality = max( 40, min( 100, $quality ) );
		$batch   = max( 1, min( 8, $batch ) );

		$result = $this->converter->convert_batch( $target, $quality, $last_id, $batch );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( $result );
	}

	/** Update a resumable batch of stored image URLs. */
	public function ajax_rewrite_batch() {
		$this->authorize_ajax();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$batch = isset( $_POST['rewriteBatch'] ) ? absint( wp_unslash( $_POST['rewriteBatch'] ) ) : 1000;
		$batch = max( 100, min( 2000, $batch ) );

		$result = $this->converter->rewrite_references_batch( $batch );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( $result );
	}

	/** Delete one batch of retained original files. */
	public function ajax_delete_batch() {
		$this->authorize_ajax();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$last_id = isset( $_POST['lastId'] ) ? absint( wp_unslash( $_POST['lastId'] ) ) : 0;
		$batch   = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : 2;
		$batch   = max( 1, min( 10, $batch ) );

		$result = $this->converter->delete_originals_batch( $last_id, $batch );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success( $result );
	}

	/** Validate the capabilities required for destructive Media Library operations. */
	private function authorize_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage media files.', 'compact-img-compress-convert-images-to-webpavif' ) ), 403 );
		}
	}

	/**
	 * Validate the requested output format.
	 *
	 * @param string $target Sanitized output format key.
	 * @return string
	 */
	private function validate_target( $target ) {
		return in_array( $target, array( 'webp', 'avif', 'jpeg' ), true ) ? $target : 'webp';
	}
}
