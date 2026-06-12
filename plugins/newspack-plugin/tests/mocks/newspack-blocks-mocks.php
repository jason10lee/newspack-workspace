<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing

namespace Newspack_Blocks;

/**
 * Minimal mock of Newspack_Blocks\Modal_Checkout, used when newspack-blocks is
 * not loaded in the test environment.
 *
 * The $is_modal_checkout flag defaults to false so that unrelated code paths
 * guarded by method_exists + is_modal_checkout() behave the same as when the
 * class is absent. Tests that need a modal checkout request set the flag and
 * the shared set_up() resets it.
 */
class Modal_Checkout {
	public static $is_modal_checkout = false;
	public static function is_modal_checkout() {
		return self::$is_modal_checkout;
	}
}
