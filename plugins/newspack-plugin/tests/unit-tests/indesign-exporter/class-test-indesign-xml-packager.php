<?php
/**
 * Tests for InDesign_XML_Packager.
 *
 * @package Newspack\Tests
 */

use Newspack\Optional_Modules\InDesign_Export\InDesign_XML_Packager;

class Newspack_Test_InDesign_XML_Packager extends WP_UnitTestCase {

	/**
	 * @var InDesign_XML_Packager
	 */
	private $packager;

	/**
	 * @var string[]
	 */
	private $temp_dirs_to_clean = [];

	/**
	 * Set up the test.
	 */
	public function set_up() {
		parent::set_up();
		$this->packager = new InDesign_XML_Packager();
	}

	/**
	 * Clean up any temp dirs created during the test.
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs_to_clean as $dir ) {
			$this->packager->cleanup( $dir );
		}
		$this->temp_dirs_to_clean = [];
		parent::tear_down();
	}

	/**
	 * Single-post ZIP contains article.xml at the root.
	 */
	public function test_single_post_zip_contains_article_xml() {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Hello' ] );
		$post    = get_post( $post_id );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?><article><headline>Hello</headline></article>';

		$result = $this->packager->package_single( $post, $xml, [] );
		$this->assertNotFalse( $result );
		$this->temp_dirs_to_clean[] = $result['temp_dir'];

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $result['zip_path'] ) );
		$this->assertNotFalse( $zip->locateName( 'article.xml' ) );
		$xml_in_zip = $zip->getFromName( 'article.xml' );
		$this->assertStringContainsString( '<headline>Hello</headline>', $xml_in_zip );
		$zip->close();
	}

	/**
	 * Image referenced in image_ids is copied to images/<id>.<ext> in the ZIP.
	 */
	public function test_single_post_zip_includes_image_from_local_disk() {
		// Create a real PNG file in the uploads dir to simulate a working attachment.
		$upload_dir = wp_upload_dir();
		$png_path   = $upload_dir['basedir'] . '/test-image-' . uniqid() . '.png';
		$png_data   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		file_put_contents( $png_path, $png_data );

		$attachment_id = self::factory()->attachment->create_object(
			$png_path,
			0,
			[ 'post_mime_type' => 'image/png' ]
		);
		update_attached_file( $attachment_id, $png_path );

		$post_id = self::factory()->post->create();
		$post    = get_post( $post_id );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?><article><body><figure id="' . $attachment_id . '"><Link href="images/' . $attachment_id . '.png"/></figure></body></article>';

		$result = $this->packager->package_single( $post, $xml, [ $attachment_id ] );
		$this->assertNotFalse( $result );
		$this->temp_dirs_to_clean[] = $result['temp_dir'];

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $result['zip_path'] ) );
		$this->assertNotFalse( $zip->locateName( 'images/' . $attachment_id . '.png' ) );
		$zip->close();

		wp_delete_file( $png_path );
	}

	/**
	 * Missing attachment is skipped without failing the package.
	 */
	public function test_missing_attachment_is_skipped() {
		$post_id = self::factory()->post->create();
		$post    = get_post( $post_id );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?><article></article>';

		// 99999999 will not resolve to any attached file or remote URL.
		$result = $this->packager->package_single( $post, $xml, [ 99999999 ] );
		$this->assertNotFalse( $result );
		$this->temp_dirs_to_clean[] = $result['temp_dir'];

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $result['zip_path'] ) );
		// XML still present.
		$this->assertNotFalse( $zip->locateName( 'article.xml' ) );
		$zip->close();
	}

	/**
	 * Multi-post ZIP has per-post subdirectories (D2).
	 */
	public function test_multi_post_zip_has_per_post_subdirs() {
		$post_a = get_post(
			self::factory()->post->create( [ 'post_title' => 'Alpha story' ] )
		);
		$post_b = get_post(
			self::factory()->post->create( [ 'post_title' => 'Beta story' ] )
		);
		$xml_a  = '<?xml version="1.0" encoding="UTF-8"?><article><headline>Alpha</headline></article>';
		$xml_b  = '<?xml version="1.0" encoding="UTF-8"?><article><headline>Beta</headline></article>';

		$result = $this->packager->package_multi(
			[
				[
					'post'      => $post_a,
					'xml'       => $xml_a,
					'image_ids' => [],
				],
				[
					'post'      => $post_b,
					'xml'       => $xml_b,
					'image_ids' => [],
				],
			]
		);
		$this->assertNotFalse( $result );
		$this->temp_dirs_to_clean[] = $result['temp_dir'];

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $result['zip_path'] ) );

		$found_a = null;
		$found_b = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( null === $found_a && false !== strpos( $entry, 'post-' . $post_a->ID ) && '/article.xml' === substr( $entry, -12 ) ) {
				$found_a = $entry;
			}
			if ( null === $found_b && false !== strpos( $entry, 'post-' . $post_b->ID ) && '/article.xml' === substr( $entry, -12 ) ) {
				$found_b = $entry;
			}
		}
		$this->assertNotNull( $found_a, 'Expected post-' . $post_a->ID . '/article.xml in zip' );
		$this->assertNotNull( $found_b, 'Expected post-' . $post_b->ID . '/article.xml in zip' );
		$zip->close();
	}

	/**
	 * Cleanup removes the entire temp tree.
	 */
	public function test_cleanup_removes_temp_dir() {
		$post_id = self::factory()->post->create();
		$post    = get_post( $post_id );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?><article></article>';

		$result = $this->packager->package_single( $post, $xml, [] );
		$this->assertNotFalse( $result );
		$temp_dir = $result['temp_dir'];

		$this->assertTrue( is_dir( $temp_dir ) );
		$this->packager->cleanup( $temp_dir );
		$this->assertFalse( is_dir( $temp_dir ) );
	}
}
