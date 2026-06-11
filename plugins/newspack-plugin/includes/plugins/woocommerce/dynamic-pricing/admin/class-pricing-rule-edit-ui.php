<?php
/**
 * MVP admin UI for shop_pricing_rule CPT.
 *
 * Replaces the raw Custom Fields metabox with structured fields for the rule
 * settings + a repeatable rows interface for the stepped pricing steps. Save
 * handler writes the same flat post_meta + JSON _params shape the Pricing_Rule entity
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
use Newspack\Dynamic_Pricing\Pricing_Rule;

defined( 'ABSPATH' ) || exit;

final class Pricing_Rule_Edit_UI {
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
			__( 'Rule Settings', 'newspack-plugin' ),
			[ __CLASS__, 'render_settings_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'newspack_dp_steps',
			__( 'Price Schedule', 'newspack-plugin' ),
			[ __CLASS__, 'render_steps_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'newspack_dp_simple',
			__( 'Flat Adjustment', 'newspack-plugin' ),
			[ __CLASS__, 'render_simple_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'newspack_dp_conditions',
			__( 'Eligibility', 'newspack-plugin' ),
			[ __CLASS__, 'render_conditions_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'default'
		);
	}

	public static function render_settings_metabox( \WP_Post $post ): void {
		// Hydrate through the entity — Pricing_Rule::from_post() is the one canonical
		// decoder of rule meta; the UI must not re-implement it.
		      $rule = Pricing_Rule::from_post( $post );
		$strategy_id  = $rule->strategy_id ?: 'stepped_by_cycle';
		$priority     = $rule->priority;
		$compose_mode = $rule->compose_mode;
		$scope_type   = $rule->scope_type;
		$scope_value  = implode( ', ', $rule->scope_ids );
		$active_from  = $rule->active_from;
		$active_until = $rule->active_until;
		$publicize    = $rule->publicize;
		$application  = $rule->application;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table">
			<tr>
				<th><label for="newspack_dp_strategy_id"><?php esc_html_e( 'Pricing model', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_strategy_id" id="newspack_dp_strategy_id">
						<option value="stepped_by_cycle" <?php selected( $strategy_id, 'stepped_by_cycle' ); ?>><?php esc_html_e( 'Price Schedule — different prices for the purchase and renewals', 'newspack-plugin' ); ?></option>
						<option value="simple_price" <?php selected( $strategy_id, 'simple_price' ); ?>><?php esc_html_e( 'Flat Adjustment — one price for the purchase and renewals, optionally only the first N', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_lock_at_purchase"><?php esc_html_e( 'Lock at purchase', 'newspack-plugin' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="newspack_dp_lock_at_purchase" id="newspack_dp_lock_at_purchase" value="1" <?php checked( Pricing_Rule::APPLICATION_LOCKED === $application ); ?> />
						<?php esc_html_e( 'Snapshot this rule onto each subscription at purchase.', 'newspack-plugin' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When on, editing this rule later affects new purchases only. When off, the rule is always current — every renewal re-evaluates against the live configuration (for retention or fleet-wide adjustments).', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_compose_mode"><?php esc_html_e( 'When multiple rules match', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_compose_mode" id="newspack_dp_compose_mode">
						<option value="min" <?php selected( $compose_mode, 'min' ); ?>><?php esc_html_e( 'Best price wins (default)', 'newspack-plugin' ); ?></option>
						<option value="priority_exclusive" <?php selected( $compose_mode, 'priority_exclusive' ); ?>><?php esc_html_e( 'This rule only (stop checking others)', 'newspack-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_priority"><?php esc_html_e( 'Priority', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="number" name="newspack_dp_priority" id="newspack_dp_priority" value="<?php echo esc_attr( (string) $priority ); ?>" min="0" />
					<p class="description"><?php esc_html_e( 'Lower numbers are considered first when multiple rules match.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_scope_type"><?php esc_html_e( 'Applies to', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_scope_type" id="newspack_dp_scope_type">
						<option value="all_subscriptions" <?php selected( $scope_type, 'all_subscriptions' ); ?>><?php esc_html_e( 'All subscriptions', 'newspack-plugin' ); ?></option>
						<option value="product_ids" <?php selected( $scope_type, 'product_ids' ); ?>><?php esc_html_e( 'Specific products', 'newspack-plugin' ); ?></option>
						<option value="category" <?php selected( $scope_type, 'category' ); ?>><?php esc_html_e( 'Product categories', 'newspack-plugin' ); ?></option>
					</select>
					<p style="margin-top: 8px">
						<input
							type="text"
							name="newspack_dp_scope_value"
							id="newspack_dp_scope_value"
							value="<?php echo esc_attr( $scope_value ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Comma-separated IDs (for Specific products or Product categories)', 'newspack-plugin' ); ?>"
						/>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_active_from"><?php esc_html_e( 'Starts', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="datetime-local" name="newspack_dp_active_from" id="newspack_dp_active_from" value="<?php echo esc_attr( self::ts_to_local( $active_from ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Site timezone. Empty = active immediately.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_active_until"><?php esc_html_e( 'Ends', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="datetime-local" name="newspack_dp_active_until" id="newspack_dp_active_until" value="<?php echo esc_attr( self::ts_to_local( $active_until ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Site timezone. Empty = no end date.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_publicize"><?php esc_html_e( 'Show pricing details at checkout', 'newspack-plugin' ); ?></label></th>
				<td>
					<label style="opacity: 0.6">
						<input type="checkbox" name="newspack_dp_publicize" id="newspack_dp_publicize" value="1" disabled />
						<?php esc_html_e( 'Display this rule\'s name and the regular price comparison in the cart and at checkout.', 'newspack-plugin' ); ?>
					</label>
					<p class="description"><strong><?php esc_html_e( 'Coming soon.', 'newspack-plugin' ); ?></strong> <?php esc_html_e( 'The recurring totals shown to readers don\'t yet account for stepped pricing or per-cycle limits; the previous implementation could present incorrect numbers, so it has been removed pending a rework.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_conditions_metabox( \WP_Post $post ): void {
		$conditions      = Pricing_Rule::from_post( $post )->conditions;
		$first_time_only = self::condition_value( $conditions, 'first_time_only' );
		$started_after   = (int) self::condition_value( $conditions, 'subscription_started_after' );
		?>
		<p class="description">
			<?php esc_html_e( 'Eligibility gates whether this rule applies to a given purchase or subscription. All set conditions must pass; leaving all fields empty means no restrictions.', 'newspack-plugin' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'New subscribers only', 'newspack-plugin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="newspack_dp_conditions[first_time_only]" value="1" <?php checked( (bool) $first_time_only ); ?> />
						<?php esc_html_e( 'Only apply when the customer has never had a subscription to the scoped product.', 'newspack-plugin' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Prevents a cancelled subscriber from re-triggering an intro price by purchasing again. Only gates the checkout — existing subscribers keep their renewal terms unaffected. For "intro only, no further changes" rules, pair with a single-cycle schedule. Guests are treated as new subscribers.', 'newspack-plugin' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="newspack_dp_started_after"><?php esc_html_e( 'Subscriptions started on/after', 'newspack-plugin' ); ?></label></th>
				<td>
					<input
						type="datetime-local"
						name="newspack_dp_conditions[subscription_started_after]"
						id="newspack_dp_started_after"
						value="<?php echo esc_attr( self::ts_to_local( $started_after > 0 ? $started_after : null ) ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Cohort gate (site timezone, empty = no restriction). At renewal, only subscriptions started on or after this moment qualify — an Always-current rule created today won\'t reach back into older subscriptions. At checkout, the rule applies only once this moment has passed.', 'newspack-plugin' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Operator-facing label for an Amount_Calculator calc type. Keeps the raw
	 * enum strings on the storage side while the UI uses Woo-native phrasing.
	 */
	private static function calc_type_label( string $type ): string {
		return match ( $type ) {
			Amount_Calculator::FIXED_PRICE     => __( 'Set price to', 'newspack-plugin' ),
			Amount_Calculator::PERCENT_OF_BASE => __( 'Percentage of regular price', 'newspack-plugin' ),
			Amount_Calculator::DISCOUNT_FIXED  => __( 'Amount off regular price', 'newspack-plugin' ),
			default                            => $type,
		};
	}

	private static function condition_value( array $conditions, string $type ): mixed {
		foreach ( $conditions as $c ) {
			if ( is_array( $c ) && ( $c['type'] ?? null ) === $type ) {
				return $c['value'] ?? null;
			}
		}
		return null;
	}

	public static function render_simple_metabox( \WP_Post $post ): void {
		$params         = Pricing_Rule::from_post( $post )->params;
		$calc_type      = (string) ( $params['calc_type'] ?? Amount_Calculator::FIXED_PRICE );
		$value          = (float) ( $params['value'] ?? 0 );
		$label          = (string) ( $params['label'] ?? '' );
		$cycles_limit = max( 0, (int) ( $params['cycles_limit'] ?? 0 ) );
		?>
		<p class="description"><?php esc_html_e( 'One adjustment applied to the purchase and every renewal — or only the first N cycles. When the limit is reached, the price returns to the regular price automatically. Cycle 1 is the initial purchase; cycle 2 is the first renewal.', 'newspack-plugin' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="newspack_dp_simple_calc_type"><?php esc_html_e( 'Pricing', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_simple[calc_type]" id="newspack_dp_simple_calc_type">
						<?php foreach ( Amount_Calculator::supported_types() as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $calc_type, $type ); ?>><?php echo esc_html( self::calc_type_label( $type ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_simple_value"><?php esc_html_e( 'Value', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="number" step="0.01" min="0" name="newspack_dp_simple[value]" id="newspack_dp_simple_value" value="<?php echo esc_attr( (string) $value ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_simple_cycles_limit"><?php esc_html_e( 'Apply for first N cycles', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="number" min="0" name="newspack_dp_simple[cycles_limit]" id="newspack_dp_simple_cycles_limit" value="<?php echo esc_attr( (string) $cycles_limit ); ?>" />
					<p class="description"><?php esc_html_e( '0 = unlimited (applies to the purchase and every renewal). Otherwise, the rule covers the purchase plus the next N−1 renewals.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_simple_label"><?php esc_html_e( 'Name shown to reader', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="text" name="newspack_dp_simple[label]" id="newspack_dp_simple_label" value="<?php echo esc_attr( $label ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Used when "Show pricing details at checkout" is on.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_steps_metabox( \WP_Post $post ): void {
		$params = Pricing_Rule::from_post( $post )->params;
		$steps  = is_array( $params['steps'] ?? null ) ? $params['steps'] : [];
		?>
		<p class="description"><?php esc_html_e( 'Each row sets the price from a given cycle onward, until a later row takes over. Cycle 1 is the initial purchase; cycle 2 is the first renewal.', 'newspack-plugin' ); ?></p>
		<table id="newspack_dp_steps_table" class="widefat striped" style="margin-top: 10px">
			<thead>
				<tr>
					<th style="width: 110px"><?php esc_html_e( 'From cycle #', 'newspack-plugin' ); ?></th>
					<th><?php esc_html_e( 'Pricing', 'newspack-plugin' ); ?></th>
					<th style="width: 110px"><?php esc_html_e( 'Value', 'newspack-plugin' ); ?></th>
					<th><?php esc_html_e( 'Name shown to reader', 'newspack-plugin' ); ?></th>
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
			<button type="button" class="button" id="newspack_dp_add_step"><?php esc_html_e( '+ Add row', 'newspack-plugin' ); ?></button>
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
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $calc_type, $type ); ?>><?php echo esc_html( self::calc_type_label( $type ) ); ?></option>
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
				<button type="button" class="button newspack-dp-remove-step" title="<?php esc_attr_e( 'Remove row', 'newspack-plugin' ); ?>">&times;</button>
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

		if ( ! in_array( $strategy_id, [ 'stepped_by_cycle', 'simple_price' ], true ) ) {
			$strategy_id = 'stepped_by_cycle';
		}
		if ( ! in_array( $compose_mode, [ 'min', 'priority_exclusive' ], true ) ) {
			$compose_mode = 'min';
		}
		$application = ! empty( $_POST['newspack_dp_lock_at_purchase'] )
			? Pricing_Rule::APPLICATION_LOCKED
			: Pricing_Rule::APPLICATION_CURRENT;
		if ( ! in_array( $scope_type, [ 'all_subscriptions', 'product_ids', 'category' ], true ) ) {
			$scope_type = 'all_subscriptions';
		}

		update_post_meta( $post_id, '_strategy_id', $strategy_id );
		update_post_meta( $post_id, '_priority', $priority );
		update_post_meta( $post_id, '_compose_mode', $compose_mode );
		update_post_meta( $post_id, '_application', $application );
		update_post_meta( $post_id, '_scope_type', $scope_type );

		delete_post_meta( $post_id, '_scope_product_id' );
		delete_post_meta( $post_id, '_scope_category_id' );

		if ( in_array( $scope_type, [ 'product_ids', 'category' ], true ) ) {
			$scope_raw = isset( $_POST['newspack_dp_scope_value'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_scope_value'] ) ) : '';
			$ids       = array_filter( wp_parse_id_list( $scope_raw ), fn( int $i ): bool => $i > 0 );
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

		// The publicize checkbox is currently disabled in the UI (see the rule edit
		// screen) so the form never submits a value. Force-clear the meta so any
		// previously-checked rule is wiped to a clean state for the rework.
		update_post_meta( $post_id, '_publicize', '' );

		// Params shape follows the selected strategy's config_schema.
		if ( 'simple_price' === $strategy_id ) {
			$simple_in = isset( $_POST['newspack_dp_simple'] ) && is_array( $_POST['newspack_dp_simple'] ) ? wp_unslash( $_POST['newspack_dp_simple'] ) : [];
			$calc      = isset( $simple_in['calc_type'] ) ? sanitize_text_field( $simple_in['calc_type'] ) : Amount_Calculator::FIXED_PRICE;
			if ( ! in_array( $calc, Amount_Calculator::supported_types(), true ) ) {
				$calc = Amount_Calculator::FIXED_PRICE;
			}
			$params_out = [
				'calc_type'      => $calc,
				'value'          => max( 0, (float) ( $simple_in['value'] ?? 0 ) ),
				'cycles_limit'   => max( 0, (int) ( $simple_in['cycles_limit'] ?? 0 ) ),
				'label'          => isset( $simple_in['label'] ) ? sanitize_text_field( $simple_in['label'] ) : '',
			];
		} else {
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
			$params_out = [ 'steps' => $steps_out ];
		}
		// wp_slash: update_metadata() wp_unslash()es its value, which would strip the
		// backslashes JSON uses to escape quotes inside labels and corrupt the blob.
		update_post_meta( $post_id, '_params', wp_slash( wp_json_encode( $params_out ) ) );

		// Conditions: read the structured array from $_POST, build a normalized list of
		// {type, value} entries, and persist as JSON for Pricing_Rule::from_post() to decode.
		$conditions_in  = isset( $_POST['newspack_dp_conditions'] ) && is_array( $_POST['newspack_dp_conditions'] ) ? wp_unslash( $_POST['newspack_dp_conditions'] ) : [];
		$conditions_out = [];
		if ( ! empty( $conditions_in['first_time_only'] ) ) {
			$conditions_out[] = [
				'type'  => 'first_time_only',
				'value' => true,
			];
		}
		$started_after = self::local_to_ts( sanitize_text_field( (string) ( $conditions_in['subscription_started_after'] ?? '' ) ) );
		if ( null !== $started_after ) {
			$conditions_out[] = [
				'type'  => 'subscription_started_after',
				'value' => $started_after,
			];
		}
		if ( empty( $conditions_out ) ) {
			delete_post_meta( $post_id, '_conditions' );
		} else {
			update_post_meta( $post_id, '_conditions', wp_slash( wp_json_encode( $conditions_out ) ) );
		}
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
	function initStrategyToggle() {
		var select   = document.querySelector('#newspack_dp_strategy_id');
		var steps    = document.querySelector('#newspack_dp_steps');
		var simple   = document.querySelector('#newspack_dp_simple');
		if (!select || !steps || !simple) { return; }

		function toggle() {
			var isSimple = select.value === 'simple_price';
			steps.style.display  = isSimple ? 'none' : '';
			simple.style.display = isSimple ? '' : 'none';
		}

		select.addEventListener('change', toggle);
		toggle();
	}

	function init() {
		initStrategyToggle();

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
				$reordered['newspack_dp_strategy'] = __( 'Pricing model', 'newspack-plugin' );
				$reordered['newspack_dp_scope']    = __( 'Applies to', 'newspack-plugin' );
				$reordered['newspack_dp_priority'] = __( 'Priority', 'newspack-plugin' );
			}
		}
		return $reordered;
	}

	public static function render_list_column( string $column, int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$rule = Pricing_Rule::from_post( $post );
		switch ( $column ) {
			case 'newspack_dp_strategy':
				echo esc_html( $rule->strategy_id );
				break;
			case 'newspack_dp_scope':
				$value = implode( ', ', $rule->scope_ids );
				echo esc_html( '' !== $value ? "{$rule->scope_type}: {$value}" : $rule->scope_type );
				break;
			case 'newspack_dp_priority':
				echo esc_html( (string) $rule->priority );
				break;
		}
	}

	/**
	 * UTC timestamp → site-timezone string for the datetime-local input.
	 */
	private static function ts_to_local( ?int $ts ): string {
		if ( null === $ts ) {
			return '';
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d\TH:i' );
	}

	/**
	 * Site-timezone datetime-local input value → UTC timestamp.
	 */
	private static function local_to_ts( string $local ): ?int {
		if ( '' === $local ) {
			return null;
		}
		$gmt = get_gmt_from_date( str_replace( 'T', ' ', $local ) . ':00' );
		$ts  = strtotime( $gmt . ' UTC' );
		return false === $ts ? null : $ts;
	}
}
