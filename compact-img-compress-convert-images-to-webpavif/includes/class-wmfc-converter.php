<?php
/**
 * Image conversion and cleanup service.
 *
 * @package CompactIMG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WMFC_Converter {
	const ORIGINALS_META = '_wmfc_original_files';
	const DELETED_META   = '_wmfc_deleted_originals';
	const STATS_META     = '_wmfc_conversion_stats';
	const LOCK_OPTION    = '_wmfc_processing_lock';
	const REWRITE_OPTION = '_wmfc_rewrite_required';
	const REWRITE_STATE  = '_wmfc_rewrite_state';

	/**
	 * Count attachments that need conversion and those with retained originals.
	 *
	 * @param string $target Output format key.
	 * @return array<string,mixed>
	 */
	public function get_library_counts( $target ) {
		global $wpdb;

		$mime        = $this->target_mime( $target );
		$sql         = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND post_mime_type <> %s AND post_mime_type NOT IN ('image/svg', 'image/svg+xml')";
		$convertible = (int) $wpdb->get_var( $wpdb->prepare( $sql, $mime ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh exact count is required for the user-triggered scan.
		$with_sources = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh exact count is required for the user-triggered scan.
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::ORIGINALS_META
			)
		);
		$supported = wp_image_editor_supports( array( 'mime_type' => $mime ) );
		$metrics   = $this->get_optimization_totals();

		if ( ! $supported ) {
			$message     = sprintf(
				/* translators: %s: output format. */
				__( 'This server image editor cannot create %s files.', 'compact-img-compress-convert-images-to-webpavif' ),
				strtoupper( $target )
			);
			$convertible = 0;
		} else {
			$conversion_message = sprintf(
				/* translators: %s: number of attachments needing conversion. */
				_n( '%s attachment needs conversion.', '%s attachments need conversion.', $convertible, 'compact-img-compress-convert-images-to-webpavif' ),
				number_format_i18n( $convertible )
			);
			$cleanup_message = sprintf(
				/* translators: %s: number of attachments with retained originals. */
				_n( '%s attachment has retained originals available for cleanup.', '%s attachments have retained originals available for cleanup.', $with_sources, 'compact-img-compress-convert-images-to-webpavif' ),
				number_format_i18n( $with_sources )
			);
			$message = $conversion_message . ' ' . $cleanup_message;
		}

		return array(
			'convertible'   => $convertible,
			'withOriginals' => $with_sources,
			'supported'     => $supported,
			'rewritePending' => (bool) get_option( self::REWRITE_OPTION, false ),
			'metrics'        => $metrics,
			'message'       => $message,
		);
	}

	/**
	 * Convert a small batch of attachments.
	 *
	 * @param string $target  Output format key.
	 * @param int    $quality Image quality.
	 * @param int    $last_id Cursor attachment ID.
	 * @param int    $batch   Maximum attachments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function convert_batch( $target, $quality, $last_id, $batch ) {
		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$mime = $this->target_mime( $target );
			if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
				return new WP_Error( 'wmfc_unsupported_target', __( 'The server image editor does not support the selected output format.', 'compact-img-compress-convert-images-to-webpavif' ) );
			}

			$ids       = $this->get_attachment_ids( $mime, $last_id, $batch );
			$converted = 0;
			$skipped   = 0;
			$errors    = 0;
			$source_bytes         = 0;
			$target_bytes         = 0;
			$original_bytes_delta = 0;
			$optimized_bytes_delta = 0;
			$attachment_delta     = 0;
			$messages  = array();

			foreach ( $ids as $attachment_id ) {
				$last_id = $attachment_id;
				$result  = $this->convert_attachment( $attachment_id, $target, $quality );

				if ( is_wp_error( $result ) ) {
					++$errors;
					$messages[] = array(
						'type'    => 'error',
						'message' => sprintf( '#%1$d: %2$s', $attachment_id, $result->get_error_message() ),
					);
				} elseif ( ! empty( $result['skipped'] ) ) {
					++$skipped;
					$messages[] = array(
						'type'    => 'info',
						'message' => sprintf( '#%1$d: %2$s', $attachment_id, $result['message'] ),
					);
				} else {
					++$converted;
					$source_bytes += isset( $result['sourceBytes'] ) ? (int) $result['sourceBytes'] : 0;
					$target_bytes += isset( $result['targetBytes'] ) ? (int) $result['targetBytes'] : 0;
					$original_bytes_delta += isset( $result['originalBytesDelta'] ) ? (int) $result['originalBytesDelta'] : 0;
					$optimized_bytes_delta += isset( $result['optimizedBytesDelta'] ) ? (int) $result['optimizedBytesDelta'] : 0;
					$attachment_delta += isset( $result['attachmentDelta'] ) ? (int) $result['attachmentDelta'] : 0;
					$messages[] = array(
						'type'    => 'success',
						'message' => sprintf(
							/* translators: 1: attachment ID, 2: number of files. */
							__( '#%1$d converted (%2$d file variants).', 'compact-img-compress-convert-images-to-webpavif' ),
							$attachment_id,
							$result['files']
						),
					);
				}
			}

			if ( $converted > 0 ) {
				update_option( self::REWRITE_OPTION, 1, false );
				update_option(
					self::REWRITE_STATE,
					array(
						'table'   => 0,
						'last_id' => 0,
					),
					false
				);
			}

			return array(
				'processed'  => count( $ids ),
				'converted'  => $converted,
				'skipped'    => $skipped,
				'errorCount' => $errors,
				'sourceBytes'=> $source_bytes,
				'targetBytes'=> $target_bytes,
				'savedBytes' => $source_bytes - $target_bytes,
				'originalBytesDelta'  => $original_bytes_delta,
				'optimizedBytesDelta' => $optimized_bytes_delta,
				'attachmentDelta'     => $attachment_delta,
				'lastId'     => $last_id,
				'done'       => count( $ids ) < $batch,
				'messages'   => $messages,
			);
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Update stored image URLs by scanning each WordPress table once in resumable batches.
	 *
	 * @param int $limit Maximum database rows to inspect in this request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function rewrite_references_batch( $limit ) {
		global $wpdb;
		if ( ! get_option( self::REWRITE_OPTION, false ) ) {
			return array(
				'processed'   => 0,
				'updated'     => 0,
				'done'        => true,
				'stage'       => __( 'Complete', 'compact-img-compress-convert-images-to-webpavif' ),
				'stageNumber' => 7,
				'stageTotal'  => 7,
			);
		}

		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$map = $this->get_url_replacement_map();
			if ( empty( $map ) ) {
				delete_option( self::REWRITE_OPTION );
				delete_option( self::REWRITE_STATE );
				return array(
					'processed' => 0,
					'updated'   => 0,
					'done'      => true,
					'stage'     => __( 'Complete', 'compact-img-compress-convert-images-to-webpavif' ),
				);
			}

			$stages = array(
				array( 'label' => __( 'posts', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->posts, 'id' => 'ID', 'fields' => array( 'post_content', 'post_excerpt' ), 'type' => 'post' ),
				array( 'label' => __( 'comments', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->comments, 'id' => 'comment_ID', 'fields' => array( 'comment_content' ), 'type' => 'comment' ),
				array( 'label' => __( 'post metadata', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->postmeta, 'id' => 'meta_id', 'fields' => array( 'meta_value' ), 'type' => 'post_meta' ),
				array( 'label' => __( 'term metadata', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->termmeta, 'id' => 'meta_id', 'fields' => array( 'meta_value' ), 'type' => 'term_meta' ),
				array( 'label' => __( 'user metadata', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->usermeta, 'id' => 'umeta_id', 'fields' => array( 'meta_value' ), 'type' => 'user_meta' ),
				array( 'label' => __( 'comment metadata', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->commentmeta, 'id' => 'meta_id', 'fields' => array( 'meta_value' ), 'type' => 'comment_meta' ),
				array( 'label' => __( 'options', 'compact-img-compress-convert-images-to-webpavif' ), 'table' => $wpdb->options, 'id' => 'option_id', 'fields' => array( 'option_value' ), 'type' => 'option' ),
			);

			$state       = get_option( self::REWRITE_STATE, array() );
			$table_index = isset( $state['table'] ) ? absint( $state['table'] ) : 0;
			$last_id     = isset( $state['last_id'] ) ? absint( $state['last_id'] ) : 0;
			$table_index = min( $table_index, count( $stages ) - 1 );
			$stage       = $stages[ $table_index ];
			$stage_number = $table_index + 1;
			$rows    = $this->get_rewrite_rows( $stage['type'], $last_id, $limit );
			$updated = 0;

			foreach ( $rows as $row ) {
				$row_id  = (int) $row->{$stage['id']};
				$last_id = $row_id;
				if ( ! $this->row_may_contain_upload_url( $row, $stage['fields'] ) ) {
					continue;
				}

				if ( $this->rewrite_database_row( $stage, $row, $map ) ) {
					++$updated;
				}
			}

			if ( count( $rows ) < $limit ) {
				++$table_index;
				$last_id = 0;
			}

			$done = $table_index >= count( $stages );
			if ( $done ) {
				delete_option( self::REWRITE_OPTION );
				delete_option( self::REWRITE_STATE );
			} else {
				update_option(
					self::REWRITE_STATE,
					array(
						'table'   => $table_index,
						'last_id' => $last_id,
					),
					false
				);
			}

			return array(
				'processed'   => count( $rows ),
				'updated'     => $updated,
				'done'        => $done,
				'stage'       => $stage['label'],
				'stageNumber' => $stage_number,
				'stageTotal'  => count( $stages ),
			);
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Permanently remove retained source files in a small batch.
	 *
	 * @param int $last_id Cursor attachment ID.
	 * @param int $batch   Maximum attachments.
	 * @return array<string,mixed>|WP_Error
	 */
	public function delete_originals_batch( $last_id, $batch ) {
		if ( get_option( self::REWRITE_OPTION, false ) ) {
			return new WP_Error( 'wmfc_rewrite_pending', __( 'Stored image URLs must finish updating before retained originals can be deleted.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$lock = $this->acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				return new WP_Error( 'wmfc_upload_error', $uploads['error'] );
			}

			$ids      = $this->get_original_attachment_ids( $last_id, $batch );
			$deleted  = 0;
			$missing  = 0;
			$failed   = 0;
			$messages = array();

			foreach ( $ids as $attachment_id ) {
				$last_id = $attachment_id;
				$result  = $this->delete_attachment_originals( $attachment_id );
				$deleted += $result['deleted'];
				$missing += $result['missing'];
				$failed  += $result['failed'];
				$messages[] = array(
					'type'    => $result['failed'] > 0 ? 'error' : 'success',
					'message' => sprintf(
						/* translators: 1: attachment ID, 2: deleted count, 3: failed count. */
						__( '#%1$d cleanup: %2$d deleted, %3$d failed or protected.', 'compact-img-compress-convert-images-to-webpavif' ),
						$attachment_id,
						$result['deleted'],
						$result['failed']
					),
				);
			}

			return array(
				'processed' => count( $ids ),
				'deleted'   => $deleted,
				'missing'   => $missing,
				'failed'    => $failed,
				'lastId'    => $last_id,
				'done'      => count( $ids ) < $batch,
				'messages'  => $messages,
			);
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Convert one attachment and all registered size files.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $target        Output format key.
	 * @param int    $quality       Image quality.
	 * @return array<string,mixed>|WP_Error
	 */
	private function convert_attachment( $attachment_id, $target, $quality ) {
		$target_mime = $this->target_mime( $target );
		$source_mime = get_post_mime_type( $attachment_id );

		if ( $target_mime === $source_mime ) {
			return array( 'skipped' => true, 'message' => __( 'Already uses the selected format.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$attached_file = get_attached_file( $attachment_id, true );
		if ( ! $attached_file || ! is_file( $attached_file ) ) {
			return new WP_Error( 'wmfc_missing_file', __( 'The attached source file is missing.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'wmfc_upload_error', $uploads['error'] );
		}
		if ( empty( $uploads['basedir'] ) || ! $this->path_is_inside( $attached_file, $uploads['basedir'] ) ) {
			return new WP_Error( 'wmfc_unsafe_source', __( 'The attached image path is outside the uploads directory.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		if ( 'image/gif' === $source_mime && $this->is_animated_gif( $attached_file ) ) {
			return new WP_Error( 'wmfc_animated_gif', __( 'Animated GIF skipped to prevent animation loss.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$sources  = $this->collect_source_files( $attached_file, $metadata );
		$created  = array();
		$mapping  = array();

		foreach ( $sources as $source_path ) {
			if ( ! is_file( $source_path ) ) {
				continue;
			}

			if ( ! $this->path_is_inside( $source_path, $uploads['basedir'] ) ) {
				$this->delete_created_files( $created );
				return new WP_Error( 'wmfc_unsafe_source', __( 'A registered image path is outside the uploads directory.', 'compact-img-compress-convert-images-to-webpavif' ) );
			}

			$output_path = $this->unique_output_path( $source_path, $target );
			$editor      = wp_get_image_editor( $source_path );
			if ( is_wp_error( $editor ) ) {
				$this->delete_created_files( $created );
				return $editor;
			}

			$quality_result = $editor->set_quality( $quality );
			if ( is_wp_error( $quality_result ) ) {
				$this->delete_created_files( $created );
				return $quality_result;
			}

			$saved = $editor->save( $output_path, $target_mime );
			if ( is_wp_error( $saved ) ) {
				$this->delete_created_files( $created );
				return $saved;
			}

			$saved_path = isset( $saved['path'] ) ? $saved['path'] : $output_path;
			if ( ! is_file( $saved_path ) || ! $this->path_is_inside( $saved_path, $uploads['basedir'] ) ) {
				$this->delete_created_files( $created );
				return new WP_Error( 'wmfc_save_failed', __( 'The image editor did not create a valid output file.', 'compact-img-compress-convert-images-to-webpavif' ) );
			}

			$created[] = $saved_path;
			$mapping[ wp_normalize_path( $source_path ) ] = array(
				'source' => $source_path,
				'target' => $saved_path,
				'source_size' => (int) filesize( $source_path ),
				'width'  => isset( $saved['width'] ) ? (int) $saved['width'] : 0,
				'height' => isset( $saved['height'] ) ? (int) $saved['height'] : 0,
				'mime'   => $target_mime,
				'size'   => (int) filesize( $saved_path ),
			);
		}

		$main_key = wp_normalize_path( $attached_file );
		if ( empty( $mapping[ $main_key ] ) ) {
			$this->delete_created_files( $created );
			return new WP_Error( 'wmfc_main_not_converted', __( 'The main attachment file could not be converted.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$new_metadata     = $this->rewrite_attachment_metadata( $metadata, $attached_file, $mapping, $uploads['basedir'] );
		$new_main         = $mapping[ $main_key ]['target'];
		$new_attached_rel = $this->relative_upload_path( $new_main, $uploads['basedir'] );
		$old_attached_rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$had_originals    = metadata_exists( 'post', $attachment_id, self::ORIGINALS_META );
		$old_originals    = get_post_meta( $attachment_id, self::ORIGINALS_META, true );

		update_post_meta( $attachment_id, '_wp_attached_file', $new_attached_rel );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', $new_metadata );
		if ( $new_attached_rel !== get_post_meta( $attachment_id, '_wp_attached_file', true ) || $new_metadata !== get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $old_attached_rel );
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
			$this->delete_created_files( $created );
			return new WP_Error( 'wmfc_metadata_update_failed', __( 'WordPress could not save the converted attachment metadata.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$updated = wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $target_mime,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $old_attached_rel );
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
			$this->delete_created_files( $created );
			return $updated;
		}

		$records = $this->mapping_to_records( $mapping, $uploads );
		if ( ! $this->store_original_records( $attachment_id, $records ) ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $old_attached_rel );
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $metadata );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $source_mime,
				)
			);
			if ( $had_originals ) {
				update_post_meta( $attachment_id, self::ORIGINALS_META, $old_originals );
			} else {
				delete_post_meta( $attachment_id, self::ORIGINALS_META );
			}
			$this->delete_created_files( $created );
			return new WP_Error( 'wmfc_tracking_failed', __( 'WordPress could not store the retained-original safety record.', 'compact-img-compress-convert-images-to-webpavif' ) );
		}

		$source_bytes = 0;
		$target_bytes = 0;
		foreach ( $mapping as $item ) {
			$source_bytes += isset( $item['source_size'] ) ? (int) $item['source_size'] : 0;
			$target_bytes += isset( $item['size'] ) ? (int) $item['size'] : 0;
		}
		$existing_stats    = get_post_meta( $attachment_id, self::STATS_META, true );
		$has_existing_stats = is_array( $existing_stats ) && isset( $existing_stats['original_bytes'], $existing_stats['optimized_bytes'] );
		$previous_original  = $has_existing_stats ? max( 0, (int) $existing_stats['original_bytes'] ) : 0;
		$previous_optimized = $has_existing_stats ? max( 0, (int) $existing_stats['optimized_bytes'] ) : 0;
		$baseline_bytes     = $has_existing_stats && $previous_original > 0 ? $previous_original : $source_bytes;
		update_post_meta(
			$attachment_id,
			self::STATS_META,
			array(
				'original_bytes'  => $baseline_bytes,
				'optimized_bytes' => $target_bytes,
				'saved_bytes'     => $baseline_bytes - $target_bytes,
				'target_mime'     => $target_mime,
				'file_count'      => count( $mapping ),
				'converted_at'    => current_time( 'mysql', true ),
			)
		);

		clean_post_cache( $attachment_id );

		return array(
			'skipped'             => false,
			'files'               => count( $mapping ),
			'sourceBytes'         => $source_bytes,
			'targetBytes'         => $target_bytes,
			'originalBytesDelta'  => $baseline_bytes - $previous_original,
			'optimizedBytesDelta' => $target_bytes - $previous_optimized,
			'attachmentDelta'     => $has_existing_stats ? 0 : 1,
		);
	}

	/**
	 * Update filenames and properties stored in attachment metadata.
	 *
	 * @param array  $metadata      Existing metadata.
	 * @param string $attached_file Main source path.
	 * @param array  $mapping       Path conversion map.
	 * @param string $uploads_dir   Uploads base directory.
	 * @return array
	 */
	private function rewrite_attachment_metadata( $metadata, $attached_file, $mapping, $uploads_dir ) {
		$main     = $mapping[ wp_normalize_path( $attached_file ) ];
		$main_rel = $this->relative_upload_path( $main['target'], $uploads_dir );

		$metadata['file']     = $main_rel;
		$metadata['width']    = $main['width'];
		$metadata['height']   = $main['height'];
		$metadata['filesize'] = $main['size'];

		$source_dir = dirname( $attached_file );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$key = wp_normalize_path( path_join( $source_dir, $size_data['file'] ) );
				if ( empty( $mapping[ $key ] ) ) {
					continue;
				}

				$item = $mapping[ $key ];
				$metadata['sizes'][ $size_name ]['file']      = wp_basename( $item['target'] );
				$metadata['sizes'][ $size_name ]['mime-type'] = $item['mime'];
				$metadata['sizes'][ $size_name ]['width']     = $item['width'];
				$metadata['sizes'][ $size_name ]['height']    = $item['height'];
				$metadata['sizes'][ $size_name ]['filesize']  = $item['size'];
			}
		}

		if ( ! empty( $metadata['original_image'] ) ) {
			$key = wp_normalize_path( path_join( $source_dir, $metadata['original_image'] ) );
			if ( ! empty( $mapping[ $key ] ) ) {
				$metadata['original_image'] = wp_basename( $mapping[ $key ]['target'] );
			}
		}

		return $metadata;
	}

	/**
	 * Delete originals tracked for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string,int>
	 */
	private function delete_attachment_originals( $attachment_id ) {
		$uploads = wp_get_upload_dir();
		$records = get_post_meta( $attachment_id, self::ORIGINALS_META, true );
		$records = is_array( $records ) ? $records : array();
		$current = array_map( 'wp_normalize_path', $this->collect_source_files( get_attached_file( $attachment_id, true ), (array) wp_get_attachment_metadata( $attachment_id ) ) );
		$keep    = array();
		$result  = array( 'deleted' => 0, 'missing' => 0, 'failed' => 0 );

		foreach ( $records as $record ) {
			if ( empty( $record['source_path'] ) || $this->has_parent_path_segment( $record['source_path'] ) ) {
				++$result['failed'];
				$keep[] = $record;
				continue;
			}

			$path = path_join( $uploads['basedir'], $record['source_path'] );
			if ( ! $this->path_is_inside( $path, $uploads['basedir'] ) || in_array( wp_normalize_path( $path ), $current, true ) ) {
				++$result['failed'];
				$keep[] = $record;
				continue;
			}

			if ( ! file_exists( $path ) ) {
				++$result['missing'];
				continue;
			}

			wp_delete_file( $path );
			if ( file_exists( $path ) ) {
				++$result['failed'];
				$keep[] = $record;
			} else {
				++$result['deleted'];
			}
		}

		if ( $keep ) {
			update_post_meta( $attachment_id, self::ORIGINALS_META, $keep );
		} else {
			delete_post_meta( $attachment_id, self::ORIGINALS_META );
		}

		update_post_meta(
			$attachment_id,
			self::DELETED_META,
			array(
				'deleted_at' => current_time( 'mysql', true ),
				'deleted'    => $result['deleted'],
				'missing'    => $result['missing'],
				'failed'     => $result['failed'],
			)
		);

		return $result;
	}

	/**
	 * Collect main, size, and pre-scaled original paths without duplicates.
	 *
	 * @param string $attached_file Main path.
	 * @param array  $metadata      Attachment metadata.
	 * @return string[]
	 */
	private function collect_source_files( $attached_file, $metadata ) {
		$files = array();
		if ( $attached_file ) {
			$files[] = $attached_file;
		}

		$directory = $attached_file ? dirname( $attached_file ) : '';
		if ( $directory && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = path_join( $directory, $size['file'] );
				}
			}
		}

		if ( $directory && ! empty( $metadata['original_image'] ) ) {
			$files[] = path_join( $directory, $metadata['original_image'] );
		}

		$normalized = array();
		foreach ( $files as $file ) {
			$normalized[ wp_normalize_path( $file ) ] = $file;
		}

		return array_values( $normalized );
	}

	/** Return aggregate before-and-after byte totals for converted attachments. */
	private function get_optimization_totals() {
		global $wpdb;

		$uploads         = wp_get_upload_dir();
		$original_bytes  = 0;
		$optimized_bytes = 0;
		$attachments     = 0;
		$with_stats      = array();
		$stats_rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Current conversion statistics are required for the user-triggered scan.
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::STATS_META
			)
		);

		foreach ( $stats_rows as $row ) {
			$stats = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $stats ) || ! isset( $stats['original_bytes'], $stats['optimized_bytes'] ) ) {
				continue;
			}
			$original_bytes  += max( 0, (int) $stats['original_bytes'] );
			$optimized_bytes += max( 0, (int) $stats['optimized_bytes'] );
			$with_stats[ (int) $row->post_id ] = true;
			++$attachments;
		}

		$record_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Current retained-original records are required for the user-triggered scan.
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::ORIGINALS_META
			)
		);
		foreach ( $record_rows as $row ) {
			if ( isset( $with_stats[ (int) $row->post_id ] ) ) {
				continue;
			}

			$records = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $records ) ) {
				continue;
			}

			$sources = array();
			$targets = array();
			foreach ( $records as $record ) {
				if ( ! empty( $record['source_path'] ) ) {
					$sources[ $record['source_path'] ] = $this->tracked_file_size( $record['source_path'], $record['source_size'] ?? 0, $uploads );
				}
				if ( ! empty( $record['target_path'] ) ) {
					$targets[ $record['target_path'] ] = $this->tracked_file_size( $record['target_path'], $record['target_size'] ?? 0, $uploads );
				}
			}

			$roots  = array_diff_key( $sources, $targets );
			$leaves = array_diff_key( $targets, $sources );
			$before = array_sum( $roots ? $roots : $sources );
			$after  = array_sum( $leaves ? $leaves : $targets );
			if ( $before > 0 || $after > 0 ) {
				$original_bytes  += $before;
				$optimized_bytes += $after;
				++$attachments;
			}
		}

		$saved_bytes = $original_bytes - $optimized_bytes;
		return array(
			'attachments'    => $attachments,
			'originalBytes'  => $original_bytes,
			'optimizedBytes' => $optimized_bytes,
			'savedBytes'     => $saved_bytes,
			'savedPercent'   => $original_bytes > 0 ? round( ( $saved_bytes / $original_bytes ) * 100, 1 ) : 0,
		);
	}

	/** Return a validated tracked file size, preferring the recorded value. */
	private function tracked_file_size( $relative_path, $recorded_size, $uploads ) {
		$recorded_size = (int) $recorded_size;
		if ( $recorded_size > 0 ) {
			return $recorded_size;
		}
		if ( $this->has_parent_path_segment( $relative_path ) || empty( $uploads['basedir'] ) ) {
			return 0;
		}

		$path = path_join( $uploads['basedir'], $relative_path );
		return is_file( $path ) && $this->path_is_inside( $path, $uploads['basedir'] ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Create an unused output filename next to its source.
	 *
	 * @param string $source Source file path.
	 * @param string $target Target key.
	 * @return string
	 */
	private function unique_output_path( $source, $target ) {
		$directory = dirname( $source );
		$stem      = pathinfo( $source, PATHINFO_FILENAME );
		$extensions = array(
			'webp' => 'webp',
			'avif' => 'avif',
			'jpeg' => 'jpg',
		);
		$extension = isset( $extensions[ $target ] ) ? $extensions[ $target ] : 'webp';
		$filename  = $stem . '.' . $extension;

		if ( file_exists( path_join( $directory, $filename ) ) ) {
			$filename = wp_unique_filename( $directory, $stem . '-converted.' . $extension );
		}

		return path_join( $directory, $filename );
	}

	/** Convert path mappings to portable source/target records. */
	private function mapping_to_records( $mapping, $uploads ) {
		$records = array();
		foreach ( $mapping as $item ) {
			$source_url = $this->path_to_url( $item['source'], $uploads );
			$target_url = $this->path_to_url( $item['target'], $uploads );
			$records[]  = array(
				'source_path' => $this->relative_upload_path( $item['source'], $uploads['basedir'] ),
				'target_path' => $this->relative_upload_path( $item['target'], $uploads['basedir'] ),
				'source_size' => isset( $item['source_size'] ) ? (int) $item['source_size'] : 0,
				'target_size' => isset( $item['size'] ) ? (int) $item['size'] : 0,
				'source_url'  => $source_url,
				'target_url'  => $target_url,
				'source_uri'  => (string) wp_parse_url( $source_url, PHP_URL_PATH ),
				'target_uri'  => (string) wp_parse_url( $target_url, PHP_URL_PATH ),
			);
		}

		return $records;
	}

	/** Merge retained source records with any earlier conversion history. */
	private function store_original_records( $attachment_id, $new_records ) {
		$existing = get_post_meta( $attachment_id, self::ORIGINALS_META, true );
		$existing = is_array( $existing ) ? $existing : array();
		$merged   = array();

		foreach ( array_merge( $existing, $new_records ) as $record ) {
			if ( empty( $record['source_path'] ) ) {
				continue;
			}
			$merged[ $record['source_path'] ] = $record;
		}

		$merged = array_values( $merged );
		update_post_meta( $attachment_id, self::ORIGINALS_META, $merged );
		return $merged === get_post_meta( $attachment_id, self::ORIGINALS_META, true );
	}

	/** Build a transitive URL map from every retained-original record. */
	private function get_url_replacement_map() {
		global $wpdb;

		$values = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The migration map must reflect the latest conversion records.
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::ORIGINALS_META
			)
		);
		$map = array();

		foreach ( $values as $value ) {
			$records = maybe_unserialize( $value );
			if ( ! is_array( $records ) ) {
				continue;
			}

			foreach ( $records as $record ) {
				$source = ! empty( $record['source_uri'] ) ? $record['source_uri'] : ( $record['source_url'] ?? '' );
				$target = ! empty( $record['target_uri'] ) ? $record['target_uri'] : ( $record['target_url'] ?? '' );
				if ( $source && $target && $source !== $target ) {
					$map[ $source ] = $target;
				}
			}
		}

		foreach ( array_keys( $map ) as $source ) {
			$target = $map[ $source ];
			$seen   = array( $source => true );
			while ( isset( $map[ $target ] ) && empty( $seen[ $target ] ) ) {
				$seen[ $target ] = true;
				$target = $map[ $target ];
			}
			$map[ $source ] = $target;
		}

		$escaped = array();
		foreach ( $map as $source => $target ) {
			$escaped_source = str_replace( '/', '\\/', $source );
			if ( $escaped_source !== $source ) {
				$escaped[ $escaped_source ] = str_replace( '/', '\\/', $target );
			}
		}

		return array_merge( $map, $escaped );
	}

	/** Quickly reject rows that cannot contain an uploads URL. */
	private function row_may_contain_upload_url( $row, $fields ) {
		$uploads = wp_get_upload_dir();
		$prefix  = (string) wp_parse_url( trailingslashit( $uploads['baseurl'] ), PHP_URL_PATH );
		if ( ! $prefix ) {
			return true;
		}

		$escaped_prefix = str_replace( '/', '\\/', $prefix );
		foreach ( $fields as $field ) {
			$value = isset( $row->{$field} ) ? (string) $row->{$field} : '';
			if ( false !== strpos( $value, $prefix ) || false !== strpos( $value, $escaped_prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fetch one explicitly allowlisted database stage for the resumable URL migration.
	 *
	 * These deliberately uncached reads operate on current migration state and are
	 * bounded by the validated per-request limit.
	 *
	 * @param string $stage_type Allowlisted stage key.
	 * @param int    $last_id    Last processed row ID.
	 * @param int    $limit      Maximum rows to return.
	 * @return object[]
	 */
	private function get_rewrite_rows( $stage_type, $last_id, $limit ) {
		global $wpdb;

		switch ( $stage_type ) {
			case 'post':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
						$last_id,
						$limit
					)
				);
			case 'comment':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT comment_ID, comment_content FROM {$wpdb->comments} WHERE comment_ID > %d ORDER BY comment_ID ASC LIMIT %d",
						$last_id,
						$limit
					)
				);
			case 'post_meta':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key <> %s ORDER BY meta_id ASC LIMIT %d",
						$last_id,
						self::ORIGINALS_META,
						$limit
					)
				);
			case 'term_meta':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
						$last_id,
						$limit
					)
				);
			case 'user_meta':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE umeta_id > %d ORDER BY umeta_id ASC LIMIT %d",
						$last_id,
						$limit
					)
				);
			case 'comment_meta':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
						$last_id,
						$limit
					)
				);
			case 'option':
				return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh rows are required for this bounded migration batch.
					$wpdb->prepare(
						"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT IN (%s, %s, %s) ORDER BY option_id ASC LIMIT %d",
						$last_id,
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%',
						self::LOCK_OPTION,
						self::REWRITE_OPTION,
						self::REWRITE_STATE,
						$limit
					)
				);
			default:
				return array();
		}
	}

	/** Rewrite one database row while preserving serialized values. */
	private function rewrite_database_row( $stage, $row, $map ) {
		$row_id = (int) $row->{$stage['id']};
		if ( 'post' === $stage['type'] || 'comment' === $stage['type'] ) {
			$changes = array();
			foreach ( $stage['fields'] as $field ) {
				$old = (string) $row->{$field};
				$new = strtr( $old, $map );
				if ( $new !== $old ) {
					$changes[ $field ] = $new;
				}
			}

			if ( ! $changes ) {
				return false;
			}

			if ( 'post' === $stage['type'] ) {
				$changes['ID'] = $row_id;
				$result = wp_update_post( $changes, true );
				return ! is_wp_error( $result ) && (bool) $result;
			}

			$changes['comment_ID'] = $row_id;
			$result = wp_update_comment( $changes, true );
			return ! is_wp_error( $result ) && (bool) $result;
		}

		if ( 'option' === $stage['type'] ) {
			$option_name = isset( $row->option_name ) ? (string) $row->option_name : '';
			if ( ! $option_name ) {
				return false;
			}
			$old = get_option( $option_name );
			$new = $this->replace_map_recursive( $old, $map );
			return $new !== $old ? (bool) update_option( $option_name, $new ) : false;
		}

		$meta_types = array(
			'post_meta'    => 'post',
			'term_meta'    => 'term',
			'user_meta'    => 'user',
			'comment_meta' => 'comment',
		);
		if ( ! isset( $meta_types[ $stage['type'] ] ) ) {
			return false;
		}
		$meta_type = $meta_types[ $stage['type'] ];
		$meta      = get_metadata_by_mid( $meta_type, $row_id );
		if ( ! $meta ) {
			return false;
		}

		$new = $this->replace_map_recursive( $meta->meta_value, $map );
		return $new !== $meta->meta_value ? (bool) update_metadata_by_mid( $meta_type, $row_id, $new ) : false;
	}

	/** Recursively apply the complete URL map without corrupting serialized values. */
	private function replace_map_recursive( $value, $map ) {
		if ( is_string( $value ) ) {
			return strtr( $value, $map );
		}

		if ( is_array( $value ) ) {
			$updated = array();
			foreach ( $value as $key => $item ) {
				$updated_key             = is_string( $key ) ? strtr( $key, $map ) : $key;
				$updated[ $updated_key ] = $this->replace_map_recursive( $item, $map );
			}
			return $updated;
		}

		if ( is_object( $value ) ) {
			try {
				$copy = clone $value;
				foreach ( get_object_vars( $copy ) as $key => $item ) {
					$copy->{$key} = $this->replace_map_recursive( $item, $map );
				}
				return $copy;
			} catch ( Throwable $error ) {
				return $value;
			}
		}

		return $value;
	}

	/** Get the next IDs requiring the selected MIME type. */
	private function get_attachment_ids( $target_mime, $last_id, $batch ) {
		global $wpdb;

		$params = array( $last_id, $target_mime, $batch );
		$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d AND post_mime_type LIKE 'image/%%' AND post_mime_type <> %s AND post_mime_type NOT IN ('image/svg', 'image/svg+xml') ORDER BY ID ASC LIMIT %d";

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor-based attachment discovery must use current rows.
	}

	/** Get IDs with retained original records. */
	private function get_original_attachment_ids( $last_id, $batch ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor-based cleanup discovery must use current rows.
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d",
					self::ORIGINALS_META,
					$last_id,
					$batch
				)
			)
		);
	}

	/** Acquire a short-lived cross-request processing lock. */
	private function acquire_lock() {
		$token = wp_generate_uuid4();
		$value = array( 'token' => $token, 'time' => time() );

		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}

		$existing = get_option( self::LOCK_OPTION );
		if ( is_array( $existing ) && ! empty( $existing['time'] ) && ( time() - (int) $existing['time'] ) > 120 ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
				return $token;
			}
		}

		return new WP_Error( 'wmfc_locked', __( 'Another conversion or cleanup request is currently running. Try again shortly.', 'compact-img-compress-convert-images-to-webpavif' ) );
	}

	/** Release the processing lock only when owned by this request. */
	private function release_lock( $token ) {
		$existing = get_option( self::LOCK_OPTION );
		if (
			is_array( $existing )
			&& isset( $existing['token'] )
			&& is_string( $existing['token'] )
			&& is_string( $token )
			&& hash_equals( $existing['token'], $token )
		) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** Return the target MIME type. */
	private function target_mime( $target ) {
		$mime_types = array(
			'webp' => 'image/webp',
			'avif' => 'image/avif',
			'jpeg' => 'image/jpeg',
		);

		return isset( $mime_types[ $target ] ) ? $mime_types[ $target ] : 'image/webp';
	}

	/** Return a path relative to the uploads base directory. */
	private function relative_upload_path( $path, $base_dir ) {
		$path = wp_normalize_path( $path );
		$base = trailingslashit( wp_normalize_path( $base_dir ) );
		return ltrim( substr( $path, strlen( $base ) ), '/' );
	}

	/** Convert an uploads path to its public URL. */
	private function path_to_url( $path, $uploads ) {
		return trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $this->relative_upload_path( $path, $uploads['basedir'] ) ) );
	}

	/** Verify that a path remains within the uploads directory. */
	private function path_is_inside( $path, $base_dir ) {
		$resolved_path = realpath( $path );
		$resolved_base = realpath( $base_dir );
		$path          = wp_normalize_path( false !== $resolved_path ? $resolved_path : $path );
		$base          = trailingslashit( wp_normalize_path( false !== $resolved_base ? $resolved_base : $base_dir ) );
		return 0 === strpos( $path, $base ) && $path !== untrailingslashit( $base );
	}

	/** Reject a stored relative path containing a parent-directory segment. */
	private function has_parent_path_segment( $path ) {
		$path = '/' . trim( wp_normalize_path( $path ), '/' ) . '/';
		return false !== strpos( $path, '/../' );
	}

	/** Remove newly generated outputs after an attachment conversion failure. */
	private function delete_created_files( $paths ) {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/** Detect multiple GIF graphic-control blocks using the WordPress filesystem API. */
	private function is_animated_gif( $path ) {
		if ( ! is_file( $path ) ) {
			return true;
		}

		$file_size = (int) filesize( $path );
		if ( $file_size <= 0 || $file_size > 20 * MB_IN_BYTES ) {
			return true;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return true;
		}

		$contents = $wp_filesystem->get_contents( $path );
		if ( false === $contents ) {
			return true;
		}

		return preg_match_all( '/\x21\xF9\x04/s', $contents ) > 1;
	}
}
