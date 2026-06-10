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
use Newspack\Dynamic_Pricing\Policy;

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
		add_meta_box(
			'newspack_dp_simple',
			__( 'Simple Pricing', 'newspack-plugin' ),
			[ __CLASS__, 'render_simple_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'high'
		);
		add_meta_box(
			'newspack_dp_conditions',
			__( 'Eligibility Conditions', 'newspack-plugin' ),
			[ __CLASS__, 'render_conditions_metabox' ],
			Dynamic_Pricing::CPT,
			'normal',
			'default'
		);
	}

	public static function render_settings_metabox( \WP_Post $post ): void {
		// Hydrate through the entity — Policy::from_post() is the one canonical
		// decoder of policy meta; the UI must not re-implement it.
		$policy       = Policy::from_post( $post );
		$strategy_id  = $policy->strategy_id ?: 'stepped_by_cycle';
		$priority     = $policy->priority;
		$compose_mode = $policy->compose_mode;
		$scope_type   = $policy->scope_type;
		$scope_value  = implode( ', ', $policy->scope_ids );
		$active_from  = $policy->active_from;
		$active_until = $policy->active_until;
		$publicize    = $policy->publicize;
		$application  = $policy->application;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<table class="form-table">
			<tr>
				<th><label for="newspack_dp_strategy_id"><?php esc_html_e( 'Strategy', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_strategy_id" id="newspack_dp_strategy_id">
						<option value="stepped_by_cycle" <?php selected( $strategy_id, 'stepped_by_cycle' ); ?>><?php esc_html_e( 'Stepped by cycle — price changes on a payment schedule', 'newspack-plugin' ); ?></option>
						<option value="simple_price" <?php selected( $strategy_id, 'simple_price' ); ?>><?php esc_html_e( 'Simple — one adjustment, optionally for the first N payments', 'newspack-plugin' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Additional strategies register at runtime via Pricing_Engine::register().', 'newspack-plugin' ); ?></p>
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
				<th><label for="newspack_dp_application"><?php esc_html_e( 'How this policy applies', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_application" id="newspack_dp_application">
						<option value="<?php echo esc_attr( Policy::APPLICATION_DEAL ); ?>" <?php selected( $application, Policy::APPLICATION_DEAL ); ?>><?php esc_html_e( 'Deal — pinned at purchase (default)', 'newspack-plugin' ); ?></option>
						<option value="<?php echo esc_attr( Policy::APPLICATION_LIVE ); ?>" <?php selected( $application, Policy::APPLICATION_LIVE ); ?>><?php esc_html_e( 'Live — re-evaluated at every payment event', 'newspack-plugin' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Deal: the configuration is copied onto each subscription at purchase; editing the policy affects new purchases only. Live: existing subscriptions follow the current configuration at every renewal (retention offers, fleet-wide adjustments).', 'newspack-plugin' ); ?></p>
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
					<p class="description"><?php esc_html_e( 'Optional. Site timezone. Empty = active immediately.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_active_until"><?php esc_html_e( 'Active until', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="datetime-local" name="newspack_dp_active_until" id="newspack_dp_active_until" value="<?php echo esc_attr( self::ts_to_local( $active_until ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Site timezone. Empty = no expiry.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_publicize"><?php esc_html_e( 'Communicate to reader', 'newspack-plugin' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="newspack_dp_publicize" id="newspack_dp_publicize" value="1" <?php checked( $publicize ); ?> />
						<?php esc_html_e( 'Show this policy in cart / checkout (strikethrough original price + label badge)', 'newspack-plugin' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When off, the policy applies silently — the reader sees only the resolved price, no indication a discount or change occurred.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_conditions_metabox( \WP_Post $post ): void {
		$first_time_only = self::condition_value( Policy::from_post( $post )->conditions, 'first_time_only' );
		?>
		<p class="description">
			<?php esc_html_e( 'Conditions gate whether this policy applies to a given purchase. All checked conditions must pass; an unchecked policy has no eligibility restrictions.', 'newspack-plugin' ); ?>
		</p>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'First-time only', 'newspack-plugin' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="newspack_dp_conditions[first_time_only]" value="1" <?php checked( (bool) $first_time_only ); ?> />
						<?php esc_html_e( 'Only apply if the customer has never had a subscription to the scoped product (any status).', 'newspack-plugin' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Prevents a cancelled subscriber from re-triggering an intro step by purchasing again. Acquisition (cart) only — renewal cycles always pass so stepped policies keep applying after the first cycle. For "intro only, no stepping" promos, pair with a single-step policy. Guests are treated as first-time.', 'newspack-plugin' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
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
		$params         = Policy::from_post( $post )->params;
		$calc_type      = (string) ( $params['calc_type'] ?? Amount_Calculator::FIXED_PRICE );
		$value          = (float) ( $params['value'] ?? 0 );
		$label          = (string) ( $params['label'] ?? '' );
		$payments_limit = max( 0, (int) ( $params['payments_limit'] ?? 0 ) );
		?>
		<p class="description"><?php esc_html_e( 'One adjustment applied to every payment — or only the first N. When the limit is reached, the price returns to catalog automatically.', 'newspack-plugin' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="newspack_dp_simple_calc_type"><?php esc_html_e( 'Calc type', 'newspack-plugin' ); ?></label></th>
				<td>
					<select name="newspack_dp_simple[calc_type]" id="newspack_dp_simple_calc_type">
						<?php foreach ( Amount_Calculator::supported_types() as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $calc_type, $type ); ?>><?php echo esc_html( $type ); ?></option>
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
				<th><label for="newspack_dp_simple_payments_limit"><?php esc_html_e( 'Apply for first N payments', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="number" min="0" name="newspack_dp_simple[payments_limit]" id="newspack_dp_simple_payments_limit" value="<?php echo esc_attr( (string) $payments_limit ); ?>" />
					<p class="description"><?php esc_html_e( '0 = unlimited (applies to every recurring payment).', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="newspack_dp_simple_label"><?php esc_html_e( 'Label', 'newspack-plugin' ); ?></label></th>
				<td>
					<input type="text" name="newspack_dp_simple[label]" id="newspack_dp_simple_label" value="<?php echo esc_attr( $label ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Shown to the reader when the policy is publicized.', 'newspack-plugin' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_steps_metabox( \WP_Post $post ): void {
		$params = Policy::from_post( $post )->params;
		$steps  = is_array( $params['steps'] ?? null ) ? $params['steps'] : [];
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

		if ( ! in_array( $strategy_id, [ 'stepped_by_cycle', 'simple_price' ], true ) ) {
			$strategy_id = 'stepped_by_cycle';
		}
		if ( ! in_array( $compose_mode, [ 'min', 'priority_exclusive' ], true ) ) {
			$compose_mode = 'min';
		}
		$application = isset( $_POST['newspack_dp_application'] ) ? sanitize_text_field( wp_unslash( $_POST['newspack_dp_application'] ) ) : Policy::APPLICATION_DEAL;
		if ( ! in_array( $application, [ Policy::APPLICATION_DEAL, Policy::APPLICATION_LIVE ], true ) ) {
			$application = Policy::APPLICATION_DEAL;
		}
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

		$publicize = isset( $_POST['newspack_dp_publicize'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['newspack_dp_publicize'] ) );
		update_post_meta( $post_id, '_publicize', $publicize ? '1' : '' );

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
				'payments_limit' => max( 0, (int) ( $simple_in['payments_limit'] ?? 0 ) ),
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
		// {type, value} entries, and persist as JSON for Policy::from_post() to decode.
		$conditions_in  = isset( $_POST['newspack_dp_conditions'] ) && is_array( $_POST['newspack_dp_conditions'] ) ? wp_unslash( $_POST['newspack_dp_conditions'] ) : [];
		$conditions_out = [];
		if ( ! empty( $conditions_in['first_time_only'] ) ) {
			$conditions_out[] = [
				'type'  => 'first_time_only',
				'value' => true,
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
				$reordered['newspack_dp_strategy'] = __( 'Strategy', 'newspack-plugin' );
				$reordered['newspack_dp_scope']    = __( 'Scope', 'newspack-plugin' );
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
		$policy = Policy::from_post( $post );
		switch ( $column ) {
			case 'newspack_dp_strategy':
				echo esc_html( $policy->strategy_id );
				break;
			case 'newspack_dp_scope':
				$value = implode( ', ', $policy->scope_ids );
				echo esc_html( '' !== $value ? "{$policy->scope_type}: {$value}" : $policy->scope_type );
				break;
			case 'newspack_dp_priority':
				echo esc_html( (string) $policy->priority );
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
