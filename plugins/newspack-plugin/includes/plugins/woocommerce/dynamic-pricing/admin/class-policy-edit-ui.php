<?php
/**
 * MVP admin UI for shop_pricing_policy CPT.
 *
 * Replaces the raw Custom Fields metabox with structured fields for the policy
 * settings + a repeatable rows interface for the stepped pricing steps. Save
 * handler writes the same flat post_meta + JSON _params shape the Policy entity
 * factory expects, so the engine works with policies authored either way.
 *
 * Not part of the v1 spec — added for manual testing. See spec §14 future work
 * for the planned Wizard-based admin form that supersedes this.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Admin;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Dynamic_Pricing;

defined( 'ABSPATH' ) || exit;

final class Policy_Edit_UI {
	const NONCE_ACTION = 'newspack_dp_save_policy';
	const NONCE_FIELD  = 'newspack_dp_nonce';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . Dynamic_Pricing::CPT, [ __CLASS__, 'register_metaboxes' ] );
		add_action( 'save_post_' . Dynamic_Pricing::CPT, [ __CLASS__, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_filter( 'manage_' . Dynamic_Pricing::CPT . '_posts_columns', [ __CLASS__, 'list_columns' ] );
		add_action( 'manage_' . Dynamic_Pricing::CPT . '_posts_custom_column', [ __CLASS__, 'render_list_column' ], 10, 2 );
	}

	public static function register_metaboxes(): void {
		remove_meta_box( 'postcustom', Dynamic_Pricing::CPT, 'normal' );

		add_meta_box(
			'newspack_dp_policy_settings',
			__( 'Policy Settings', 'newspack-plugin' ),
			[ __CLASS__, 'render_settings_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'newspack_dp_steps',
			__( 'Stepped Pricing Steps', 'newspack-plugin' ),
			[ __CLASS__, 'render_steps_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
	}

	public static function render_settings_metabox( \WP_Post $post ): void {
		$strategy_id  = (string) get_post_meta( $post->ID, '_strategy_id', true ) ?: 'stepped_by_cycle';
		$priority     = (int) ( get_post_meta( $post->ID, '_priority', true ) ?: 100 );
		$compose_mode = (string) get_post_meta( $post->ID, '_compose_mode', true ) ?: 'min';
		$scope_type   = (string) get_post_meta( $post->ID, '_scope_type', true ) ?: 'all_subscriptions';
		$scope_value  = self::scope_value_string( $post->ID, $scope_type );
		$active_from  = (string) get_post_meta( $post->ID, '_active_from', true );
		$active_until = (string) get_post_meta( $post->ID, '_active_until', true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table">
			<tr>
				<th><label for="newspack_dp_strategy_id"><?php esc_html_e( 'Strategy', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_strategy_id" id="newspack_dp_strategy_id">
						<option value="stepped_by_cycle" <?php selected( $strategy_id, 'stepped_by_cycle' ); ?>>stepped_by_cycle</option>
					</select>
					<p class="description"><?php esc_html_e( 'v1 ships one strategy. Additional strategies register at runtime via Pricing_Engine::register().', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_priority"><?php esc_html_e( 'Priority', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="number" name="newspack_dp_priority" id="newspack_dp_priority" value="<?php echo esc_attr( (string) $priority ); ?>" min="0" />
					<p class="description"><?php esc_html_e( 'Resolution order (lowest priority first). Lower numbers win for priority_exclusive policies.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_compose_mode"><?php esc_html_e( 'Compose mode', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_compose_mode" id="newspack_dp_compose_mode">
						<option value="min" <?php selected( $compose_mode, 'min' ); ?>><?php esc_html_e( 'min — lowest amount wins (default)', 'newspack-plugin' ); ?></option>
						<option value="priority_exclusive" <?php selected( $compose_mode, 'priority_exclusive' ); ?>><?php esc_html_e( 'priority_exclusive — locks the decision, no further policies apply', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_scope_type"><?php esc_html_e( 'Scope', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_scope_type" id="newspack_dp_scope_type">
						<option value="all_subscriptions" <?php selected( $scope_type, 'all_subscriptions' ); ?>><?php esc_html_e( 'All subscriptions', 'newspack-plugin' ); ?></option>
						<option value="product_ids" <?php selected( $scope_type, 'product_ids' ); ?>><?php esc_html_e( 'Specific product IDs', 'newspack-plugin' ); ?></option>
						<option value="category" <?php selected( $scope_type, 'category' ); ?>><?php esc_html_e( 'Product category term IDs', 'newspack-plugin' ); ?></option>
					</select>
					<p style="margin-top: 8px">
						<input
							type="text"
							name="newspack_dp_scope_value"
							id="newspack_dp_scope_value"
							value="<?php echo esc_attr( $scope_value ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Comma-separated IDs (used only for product_ids / category)', 'newspack-plugin' ); ?>"
						/>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_active_from"><?php esc_html_e( 'Active from', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="datetime-local" name="newspack_dp_active_from" id="newspack_dp_active_from" value="<?php echo esc_attr( self::ts_to_local( $active_from ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. UTC. Empty = active immediately.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_active_until"><?php esc_html_e( 'Active until', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="datetime-local" name="newspack_dp_active_until" id="newspack_dp_active_until" value="<?php echo esc_attr( self::ts_to_local( $active_until ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. UTC. Empty = no expiry.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_steps_metabox( \WP_Post $post ): void {
		$params_raw = get_post_meta( $post->ID, '_params', true );
		$params     = is_string( $params_raw ) ? ( json_decode( $params_raw, true ) ?: [] ) : ( is_array( $params_raw ) ? $params_raw : [] );
		$steps      = is_array( $params['steps'] ?? null ) ? $params['steps'] : [];
		?>
		<p class="description"><?php esc_html_e( 'Each step has: cycle threshold (at), calculation type, value, and a human label. The strategy picks the highest "at" ≤ completed_cycles+1.', 'newspack-plugin' ); ?></p>
		<table id="newspack_dp_steps_table" class="widefat striped" style="margin-top: 10px">
			<thead>
				<tr>
					<th style="width: 90px"><?php esc_html_e( 'At cycle', 'newspack-plugin' ); ?></th>
					<th><?php esc_html_e( 'Calc type', 'newspack-plugin' ); ?></th>
					<th style="width: 110px"><?php esc_html_e( 'Value', 'newspack-plugin' ); ?></th>
					<th><?php esc_html_e( 'Label', 'newspack-plugin' ); ?></th>
					<th style="width: 50px"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				if ( empty( $steps ) ) {
					self::render_step_row( 0, [ 'at' => 1, 'calc_type' => Amount_Calculator::FIXED_PRICE, 'value' => 1, 'label' => 'Intro' ] );
				} else {
					foreach ( array_values( $steps ) as $i => $step ) {
						self::render_step_row( (int) $i, is_array( $step ) ? $step : [] );
					}
				}
				?>
			</tbody>
		</table>
		<p style="margin-top: 10px">
			<button type="button" class="button" id="newspack_dp_add_step"><?php esc_html_e( '+ Add step', 'newspack-plugin' ); ?></button>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to Amount_Calculator source */
				esc_html__( 'Calc types: fixed_price (set exact amount), percent_of_base (base × value/100), discount_fixed (base − value), discount_percent (base × (1 − value/100)).', 'newspack-plugin' ),
				''
			);
			?>
		</p>
		<?php
	}

	private static function render_step_row( int $index, array $step ): void {
		$at        = (int) ( $step['at'] ?? 1 );
		$calc_type = (string) ( $step['calc_type'] ?? Amount_Calculator::FIXED_PRICE );
		$value     = (float) ( $step['value'] ?? 0 );
		$label     = (string) ( $step['label'] ?? '' );
		?>
		<tr class="newspack-dp-step-row" data-index="<?php echo esc_attr( (string) $index ); ?>">
			<td>
				<input type="number" name="newspack_dp_steps[<?php echo esc_attr( (string) $index ); ?>][at]" value="<?php echo esc_attr( (string) $at ); ?>" min="1" />
			</td>
			<td>
				<select name="newspack_dp_steps[<?php echo esc_attr( (string) $index ); ?>][calc_type]">
					<?php foreach ( Amount_Calculator::supported_types() as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $calc_type, $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="number" step="0.01" name="newspack_dp_steps[<?php echo esc_attr( (string) $index ); ?>][value]" value="<?php echo esc_attr( (string) $value ); ?>" />
			</td>
			<td>
				<input type="text" name="newspack_dp_steps[<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="regular-text" />
			</td>
			<td>
				<button type="button" class="button newspack-dp-remove-step" title="<?php esc_attr_e( 'Remove step', 'newspack-plugin' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$strategy_id  = isset( $_POST['newspack_dp_strategy_id'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_strategy_id'] ) ) : 'stepped_by_cycle';
		$priority     = isset( $_POST['newspack_dp_priority'] ) ? (int) wp_unslash( $_POST['newspack_dp_priority'] ) : 100;
		$compose_mode = isset( $_POST['newspack_dp_compose_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_compose_mode'] ) ) : 'min';
		$scope_type   = isset( $_POST['newspack_dp_scope_type'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_scope_type'] ) ) : 'all_subscriptions';

		if ( ! in_array( $compose_mode, [ 'min', 'priority_exclusive' ], true ) ) {
			$compose_mode = 'min';
		}
		if ( ! in_array( $scope_type, [ 'all_subscriptions', 'product_ids', 'category' ], true ) ) {
			$scope_type = 'all_subscriptions';
		}

		update_post_meta( $post_id, '_strategy_id', $strategy_id );
		update_post_meta( $post_id, '_priority', $priority );
		update_post_meta( $post_id, '_compose_mode', $compose_mode );
		update_post_meta( $post_id, '_scope_type', $scope_type );

		delete_post_meta( $post_id, '_scope_product_id' );
		delete_post_meta( $post_id, '_scope_category_id' );

		if ( in_array( $scope_type, [ 'product_ids', 'category' ], true ) ) {
			$scope_raw = isset( $_POST['newspack_dp_scope_value'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_scope_value'] ) ) : '';
			$ids       = array_filter(
				array_map( 'intval', array_map( 'trim', explode( ',', $scope_raw ) ) ),
				fn( int $i ): bool => $i > 0
			);
			$meta_key  = 'product_ids' === $scope_type ? '_scope_product_id' : '_scope_category_id';
			foreach ( $ids as $id ) {
				add_post_meta( $post_id, $meta_key, $id );
			}
		}

		$active_from  = isset( $_POST['newspack_dp_active_from'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_active_from'] ) ) : '';
		$active_until = isset( $_POST['newspack_dp_active_until'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_active_until'] ) ) : '';
		$from_ts      = self::local_to_ts( $active_from );
		$until_ts     = self::local_to_ts( $active_until );

		if ( null === $from_ts ) {
			delete_post_meta( $post_id, '_active_from' );
		} else {
			update_post_meta( $post_id, '_active_from', $from_ts );
		}
		if ( null === $until_ts ) {
			delete_post_meta( $post_id, '_active_until' );
		} else {
			update_post_meta( $post_id, '_active_until', $until_ts );
		}

		$steps_in  = isset( $_POST['newspack_dp_steps'] ) && is_array( $_POST['newspack_dp_steps'] ) ? wp_unslash( $_POST['newspack_dp_steps'] ) : [];
		$steps_out = [];
		foreach ( $steps_in as $row ) {
			if ( ! is_array( $row ) || empty( $row['at'] ) ) {
				continue;
			}
			$calc = isset( $row['calc_type'] ) ? sanitize_text_field( $row['calc_type'] ) : Amount_Calculator::FIXED_PRICE;
			if ( ! in_array( $calc, Amount_Calculator::supported_types(), true ) ) {
				$calc = Amount_Calculator::FIXED_PRICE;
			}
			$steps_out[] = [
				'at'        => (int) $row['at'],
				'calc_type' => $calc,
				'value'     => (float) ( $row['value'] ?? 0 ),
				'label'     => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
			];
		}
		usort( $steps_out, fn( array $a, array $b ): int => $a['at'] <=> $b['at'] );
		update_post_meta( $post_id, '_params', wp_json_encode( [ 'steps' => $steps_out ] ) );
	}

	public static function enqueue_assets( string $hook ): void {
		global $post_type;
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		if ( Dynamic_Pricing::CPT !== $post_type ) {
			return;
		}
		wp_register_script( 'newspack_dp_steps', '', [], '1.0', true );
		wp_enqueue_script( 'newspack_dp_steps' );
		wp_add_inline_script( 'newspack_dp_steps', self::steps_js() );
	}

	private static function steps_js(): string {
		return <<<'JS'
(function() {
	function init() {
		var tbody  = document.querySelector('#newspack_dp_steps_table tbody');
		var addBtn = document.querySelector('#newspack_dp_add_step');
		if (!tbody || !addBtn) { return; }

		function reindex() {
			var rows = tbody.querySelectorAll('tr.newspack-dp-step-row');
			rows.forEach(function(row, i) {
				row.setAttribute('data-index', String(i));
				row.querySelectorAll('[name]').forEach(function(el) {
					el.name = el.name.replace(/newspack_dp_steps\[\d+\]/, 'newspack_dp_steps[' + i + ']');
				});
			});
		}

		addBtn.addEventListener('click', function() {
			var last = tbody.querySelector('tr.newspack-dp-step-row:last-child');
			if (!last) { return; }
			var clone = last.cloneNode(true);
			clone.querySelectorAll('input').forEach(function(el) {
				if (el.type === 'number') { el.value = ''; }
				if (el.type === 'text')   { el.value = ''; }
			});
			tbody.appendChild(clone);
			reindex();
		});

		tbody.addEventListener('click', function(e) {
			if (e.target && e.target.matches('.newspack-dp-remove-step')) {
				var row = e.target.closest('tr.newspack-dp-step-row');
				if (row && tbody.querySelectorAll('tr.newspack-dp-step-row').length > 1) {
					row.remove();
					reindex();
				}
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
JS;
	}

	public static function list_columns( array $columns ): array {
		$reordered = [];
		foreach ( $columns as $key => $label ) {
			$reordered[ $key ] = $label;
			if ( 'title' === $key ) {
				$reordered['newspack_dp_strategy'] = __( 'Strategy', 'newspack-plugin' );
				$reordered['newspack_dp_scope']    = __( 'Scope', 'newspack-plugin' );
				$reordered['newspack_dp_priority'] = __( 'Priority', 'newspack-plugin' );
			}
		}
		return $reordered;
	}

	public static function render_list_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'newspack_dp_strategy':
				echo esc_html( (string) get_post_meta( $post_id, '_strategy_id', true ) );
				break;
			case 'newspack_dp_scope':
				$scope_type = (string) get_post_meta( $post_id, '_scope_type', true );
				$value      = self::scope_value_string( $post_id, $scope_type );
				echo esc_html( '' !== $value ? "{$scope_type}: {$value}" : $scope_type );
				break;
			case 'newspack_dp_priority':
				echo esc_html( (string) (int) ( get_post_meta( $post_id, '_priority', true ) ?: 100 ) );
				break;
		}
	}

	private static function scope_value_string( int $post_id, string $scope_type ): string {
		return match ( $scope_type ) {
			'product_ids' => implode( ', ', array_map( 'intval', (array) get_post_meta( $post_id, '_scope_product_id', false ) ) ),
			'category'    => implode( ', ', array_map( 'intval', (array) get_post_meta( $post_id, '_scope_category_id', false ) ) ),
			default       => '',
		};
	}

	private static function ts_to_local( string $ts ): string {
		if ( '' === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i', (int) $ts );
	}

	private static function local_to_ts( string $local ): ?int {
		if ( '' === $local ) {
			return null;
		}
		$ts = strtotime( $local . ':00 UTC' );
		return false === $ts ? null : (int) $ts;
	}
}
