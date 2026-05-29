<?php
/**
 * InDesign XML Packager - Builds a ZIP containing XML and image binaries.
 *
 * @package Newspack
 */

namespace Newspack\Optional_Modules\InDesign_Export;

defined( 'ABSPATH' ) || exit;

/**
 * Packages InDesign XML output and referenced image binaries into a ZIP.
 *
 * Single post: zip layout is article.xml + images/N.ext at the root.
 * Multi post : zip layout is post-<id>-<slug>/article.xml + images/N.ext per post (D2).
 *
 * Image source: get_attached_file() local path first, falling back to wp_remote_get()
 * on the attachment URL. Failed downloads are logged and skipped. (D4)
 */
class InDesign_XML_Packager {

	/**
	 * Build a single-post ZIP and return its path on disk. Caller is responsible
	 * for streaming and cleanup via cleanup().
	 *
	 * @param \WP_Post $post      Post object.
	 * @param string   $xml       XML content from InDesign_XML_Converter::convert_post().
	 * @param int[]    $image_ids Attachment IDs referenced by the XML.
	 * @return array|false ['zip_path' => string, 'temp_dir' => string], or false on failure.
	 */
	public function package_single( $post, $xml, $image_ids ) {
		$temp_dir = $this->make_temp_dir();
		if ( false === $temp_dir ) {
			return false;
		}

		$post_dir = $temp_dir;

		if ( false === file_put_contents( $post_dir . '/article.xml', $xml ) ) {
			$this->rrmdir( $temp_dir );
			return false;
		}

		if ( ! empty( $image_ids ) ) {
			$this->copy_images_to( $image_ids, $post_dir );
		}

		$zip_filename = sprintf(
			'indesign-export-%d-%s-%s.zip',
			$post->ID,
			sanitize_title( $post->post_title ),
			gmdate( 'Y-m-d' )
		);
		$zip_path     = $temp_dir . '/' . $zip_filename;

		if ( ! $this->build_zip( $zip_path, $post_dir, '' ) ) {
			$this->rrmdir( $temp_dir );
			return false;
		}

		return [
			'zip_path' => $zip_path,
			'temp_dir' => $temp_dir,
		];
	}

	/**
	 * Build a multi-post ZIP with per-post subdirectories.
	 *
	 * @param array $items List of ['post' => WP_Post, 'xml' => string, 'image_ids' => int[]].
	 * @return array|false ['zip_path' => string, 'temp_dir' => string], or false on failure.
	 */
	public function package_multi( $items ) {
		$temp_dir = $this->make_temp_dir();
		if ( false === $temp_dir ) {
			return false;
		}

		foreach ( $items as $item ) {
			$post     = $item['post'];
			$subdir   = sprintf( 'post-%d-%s', $post->ID, sanitize_title( $post->post_title ) );
			$post_dir = $temp_dir . '/' . $subdir;
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Writing inside wp-content/uploads/ is allowed; subdir needed for per-post layout.
			if ( ! mkdir( $post_dir, 0755, true ) ) {
				$this->rrmdir( $temp_dir );
				return false;
			}
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Target lives inside wp-content/uploads/ subdir resolved from wp_upload_dir().
			if ( false === file_put_contents( $post_dir . '/article.xml', $item['xml'] ) ) {
				$this->rrmdir( $temp_dir );
				return false;
			}
			if ( ! empty( $item['image_ids'] ) ) {
				$this->copy_images_to( $item['image_ids'], $post_dir );
			}
		}

		$zip_filename = 'indesign-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.zip';
		$zip_path     = $temp_dir . '/' . $zip_filename;

		if ( ! $this->build_zip( $zip_path, $temp_dir, $zip_filename ) ) {
			$this->rrmdir( $temp_dir );
			return false;
		}

		return [
			'zip_path' => $zip_path,
			'temp_dir' => $temp_dir,
		];
	}

	/**
	 * Delete the temp directory tree.
	 *
	 * @param string $temp_dir Absolute path returned from package_*.
	 */
	public function cleanup( $temp_dir ) {
		if ( empty( $temp_dir ) || ! is_dir( $temp_dir ) ) {
			return;
		}
		$this->rrmdir( $temp_dir );
	}

	/**
	 * Recursive rmdir.
	 *
	 * @param string $dir Directory to remove.
	 */
	private function rrmdir( $dir ) {
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Removing temp dir we created inside wp-content/uploads/.
		rmdir( $dir );
	}

	/**
	 * Create the temp directory.
	 *
	 * @return string|false Absolute temp dir path, or false on failure.
	 */
	private function make_temp_dir() {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/indesign_export_' . uniqid();
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Writing inside wp-content/uploads/ is allowed.
		if ( ! mkdir( $temp_dir, 0755, true ) ) {
			return false;
		}
		return $temp_dir;
	}

	/**
	 * Copy image binaries into <post_dir>/images/<id>.<ext>.
	 *
	 * Local-first via get_attached_file(); HTTP fallback via wp_remote_get(). (D4)
	 *
	 * @param int[]  $image_ids Attachment IDs.
	 * @param string $post_dir  Directory to write images/ into.
	 */
	private function copy_images_to( $image_ids, $post_dir ) {
		$images_dir = $post_dir . '/images';
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir -- Writing inside wp-content/uploads/ subdir.
		if ( ! is_dir( $images_dir ) && ! mkdir( $images_dir, 0755, true ) ) {
			return;
		}

		$size = apply_filters( 'newspack_indesign_export_image_size', 'full' );

		foreach ( array_unique( array_map( 'intval', $image_ids ) ) as $id ) {
			if ( ! $id ) {
				continue;
			}
			$ext = $this->resolve_extension( $id );
			if ( '' === $ext ) {
				continue;
			}
			$target = $images_dir . '/' . $id . '.' . $ext;

			// Local first. Fall through to HTTP if copy fails (e.g. permissions, disk full).
			$local_path = $this->local_path_for_size( $id, $size );
			if ( $local_path && is_readable( $local_path ) && @copy( $local_path, $target ) ) {
				continue;
			}

			// HTTP fallback.
			$url = wp_get_attachment_image_url( $id, $size );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $id );
			}
			if ( $url ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- 5s is too tight for binary image fetches that may be many MB.
				$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
				if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
					// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Target lives in wp-content/uploads/ subdir.
					if ( false !== file_put_contents( $target, wp_remote_retrieve_body( $response ) ) ) {
						continue;
					}
				}
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operator-facing diagnostic; nothing in this path is user-actionable.
			error_log( sprintf( '[InDesign XML export] Could not bundle attachment %d: neither local nor HTTP source available.', $id ) );
		}
	}

	/**
	 * Resolve the file extension for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Extension (no dot), or empty string.
	 */
	private function resolve_extension( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( $file ) {
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( $ext ) {
				return $ext;
			}
		}
		$mime = get_post_mime_type( $attachment_id );
		switch ( $mime ) {
			case 'image/jpeg':
				return 'jpg';
			case 'image/png':
				return 'png';
			case 'image/gif':
				return 'gif';
			case 'image/webp':
				return 'webp';
		}
		return '';
	}

	/**
	 * Resolve the local disk path for a specific image size, falling back to full.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Image size slug.
	 * @return string|null Absolute path or null.
	 */
	private function local_path_for_size( $attachment_id, $size ) {
		if ( 'full' === $size || 'original' === $size ) {
			$path = get_attached_file( $attachment_id );
			return $path ? $path : null;
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir   = $upload_dir['basedir'];
			$rel_dir    = isset( $meta['file'] ) ? dirname( $meta['file'] ) : '';
			return $base_dir . '/' . ( $rel_dir ? $rel_dir . '/' : '' ) . $meta['sizes'][ $size ]['file'];
		}
		// Fall back to full.
		$path = get_attached_file( $attachment_id );
		return $path ? $path : null;
	}

	/**
	 * Build a ZIP at $zip_path containing the contents of $source_dir,
	 * skipping the zip file itself if it lives in $source_dir.
	 *
	 * @param string $zip_path      Destination zip.
	 * @param string $source_dir    Source directory.
	 * @param string $skip_basename Filename to skip (the zip itself).
	 * @return bool
	 */
	private function build_zip( $zip_path, $source_dir, $skip_basename ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$this->add_dir_to_zip( $zip, $source_dir, '', $skip_basename );
		$zip->close();
		return true;
	}

	/**
	 * Recursively add a directory's contents to an open ZipArchive.
	 *
	 * @param \ZipArchive $zip           Open zip handle.
	 * @param string      $source_dir    Absolute source dir.
	 * @param string      $zip_subdir    Relative path inside the zip (empty for root).
	 * @param string      $skip_basename Basename to skip at the root level.
	 */
	private function add_dir_to_zip( $zip, $source_dir, $zip_subdir, $skip_basename ) {
		$items = scandir( $source_dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( '' === $zip_subdir && $item === $skip_basename ) {
				continue;
			}
			$src      = $source_dir . '/' . $item;
			$zip_path = '' === $zip_subdir ? $item : $zip_subdir . '/' . $item;
			if ( is_dir( $src ) ) {
				$zip->addEmptyDir( $zip_path );
				$this->add_dir_to_zip( $zip, $src, $zip_path, $skip_basename );
			} else {
				$zip->addFile( $src, $zip_path );
			}
		}
	}
}
