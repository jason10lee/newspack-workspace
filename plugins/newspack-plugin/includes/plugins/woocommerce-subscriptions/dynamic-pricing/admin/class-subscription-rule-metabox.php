<?php
/**
 * Subscription edit screen metabox: what pricing rule is in effect.
 *
 * Pinning made operators' lives harder to inspect: a subscription paying
 * $7.50 under a snapshot looks like nothing in particular in the dashboard,
 * and the rule it was locked to may have been edited or deleted since. This
 * metabox explains the terms in effect — the snapshot's own configuration,
 * the subscription's position in it, whether the live rule has drifted from
 * it, and what the engine resolves for the next renewal.
 *
 * Display-only. No save handler; the snapshot is managed by checkout
 * (Subscription_Pin) and the migrate CLI.
 *
 * @package Newspack
 */

namespace Newspack\Dynamic_Pricing\Subscriptions;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Pricing_Rule;
use Newspack\Dynamic_Pricing\Subscription_Pin;
use Newspack\Dynamic_Pricing\WooProduct_Surface;

defined( 'ABSPATH' ) || exit;

final class Subscription_Rule_Metabox {
	public static function init(): void {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ], 30, 2 );
	}

	/**
	 * Register the metabox on subscription edit screens (legacy post screen
	 * and the HPOS order screen), only when there is something to explain.
	 *
	 * @param string $screen_id      Post type or HPOS screen id.
	 * @param mixed  $post_or_order  WP_Post (legacy) or order object (HPOS).
	 */
	public static function register( $screen_id, $post_or_order = null ): void {
		$screen_ids = [ 'shop_subscription' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop_subscription' );
		}
		if ( ! in_array( $screen_id, $screen_ids, true ) ) {
			return;
		}

		$sub = self::resolve_subscription( $post_or_order );
		if ( ! $sub ) {
			return;
		}
		$line = self::recurring_line( $sub );
		if ( ! $line ) {
			return;
		}
		if ( ! Subscription_Pin::snapshot( $line ) && '' === (string) $line->get_meta( WooProduct_Surface::LINE_META_RULE_ID ) ) {
			return; // No rule ever touched this subscription.
		}

		add_meta_box(
			'newspack-dp-subscription-rule',
			__( 'Pricing Rule', 'newspack-plugin' ),
			function () use ( $sub ) {
				self::render( $sub );
			},
			null, // Current screen — works for both legacy and HPOS.
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @internal Public for tests; the registered callback closes over the
	 * already-resolved subscription.
	 *
	 * @param \WC_Subscription $sub Subscription being edited.
	 */
	public static function render( $sub ): void {
		$line = self::recurring_line( $sub );
		if ( ! $line ) {
			return;
		}

		$snapshot = Subscription_Pin::snapshot( $line );
		$rule_id  = (string) $line->get_meta( WooProduct_Surface::LINE_META_RULE_ID );
		$product  = $line->get_product();
		$base     = $product instanceof \WC_Product ? Amount_Calculator::base_price_for( $product ) : 0.0;

		echo '<div class="newspack-dp-subscription-rule">';

		if ( $snapshot ) {
			self::render_pinned( $sub, $snapshot, $base );
		} else {
			self::render_always_current( $rule_id );
		}

		self::render_next_renewal( $sub );

		echo '</div>';
	}

	/**
	 * The locked-at-purchase explanation: provenance, drift status, and the
	 * snapshot's own configuration with the subscription's position marked.
	 *
	 * @param \WC_Subscription $sub      Subscription.
	 * @param array            $snapshot Pin snapshot.
	 * @param float            $base     Regular recurring price (0 when unknown).
	 */
	private static function render_pinned( $sub, array $snapshot, float $base ): void {
		$pinned    = Pricing_Rule::from_snapshot( $snapshot );
		$rule_id   = (int) $pinned->id;
		$title     = '' !== $pinned->title ? $pinned->title : sprintf( '#%d', $rule_id );
		$edit_link = $rule_id ? get_edit_post_link( $rule_id ) : null;
		$pinned_at = (string) ( $snapshot['pinned_at'] ?? '' );

		echo '<p>';
		printf(
			/* translators: 1: linked rule title, 2: rule id */
			wp_kses_post( __( 'Locked at purchase to %1$s (#%2$d).', 'newspack-plugin' ) ),
			$edit_link ? '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a>' : '<strong>' . esc_html( $title ) . '</strong>',
			(int) $rule_id
		);
		if ( '' !== $pinned_at ) {
			echo ' ';
			printf(
				/* translators: %s: localized date */
				esc_html__( 'Snapshotted on %s (UTC).', 'newspack-plugin' ),
				esc_html( date_i18n( get_option( 'date_format' ), strtotime( $pinned_at ) ) )
			);
		}
		echo '</p>';

		// Drift status: the reason this metabox exists. Edit-immunity is
		// invisible until an operator wonders why the subscription's terms
		// differ from the rule's current settings.
		$live = $rule_id ? get_post( $rule_id ) : null;
		if ( ! $live || 'shop_pricing_rule' !== $live->post_type ) {
			echo '<p><em>' . esc_html__( 'The rule no longer exists — the snapshot below remains in effect.', 'newspack-plugin' ) . '</em></p>';
		} else {
			$live_rule = Pricing_Rule::from_post( $live );
			$drifted   = wp_json_encode( $live_rule->params ) !== wp_json_encode( $pinned->params )
				|| $live_rule->strategy_id !== $pinned->strategy_id;
			if ( $drifted ) {
				echo '<p><em>' . esc_html__( 'The rule has been edited since — this subscription keeps the snapshot below. Use the migrate CLI to re-lock it to the current configuration.', 'newspack-plugin' ) . '</em></p>';
			} else {
				echo '<p class="description">' . esc_html__( 'The snapshot matches the rule’s current configuration.', 'newspack-plugin' ) . '</p>';
			}
		}

		self::render_config( $pinned, $base, self::upcoming_cycle( $sub ) );
	}

	/**
	 * The always-current explanation — there is no snapshot; renewals follow
	 * the live rule.
	 *
	 * @param string $rule_id Attributed rule id from line meta.
	 */
	private static function render_always_current( string $rule_id ): void {
		$id    = (int) $rule_id;
		$live  = $id ? get_post( $id ) : null;
		$valid = $live && 'shop_pricing_rule' === $live->post_type;

		echo '<p>';
		if ( $valid ) {
			$edit_link = get_edit_post_link( $id );
			printf(
				/* translators: 1: linked rule title, 2: rule id */
				wp_kses_post( __( 'Priced by %1$s (#%2$d) — Always current. Renewals follow the rule’s configuration at each renewal; no snapshot is held.', 'newspack-plugin' ) ),
				$edit_link ? '<a href="' . esc_url( $edit_link ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : '<strong>' . esc_html( get_the_title( $id ) ) . '</strong>',
				$id
			);
		} else {
			printf(
				/* translators: %d: rule id */
				esc_html__( 'Last priced by rule #%d, which no longer exists. Renewals follow regular pricing unless another rule matches.', 'newspack-plugin' ),
				$id
			);
		}
		echo '</p>';
	}

	/**
	 * The configuration in effect, rendered from the SNAPSHOT params (not the
	 * live rule), with the row governing the upcoming cycle marked.
	 *
	 * @param Pricing_Rule $rule     Snapshot-hydrated rule.
	 * @param float        $base     Regular recurring price (0 when unknown).
	 * @param int          $upcoming Upcoming cycle number.
	 */
	private static function render_config( Pricing_Rule $rule, float $base, int $upcoming ): void {
		if ( 'stepped_by_cycle' === $rule->strategy_id ) {
			$steps = is_array( $rule->params['steps'] ?? null ) ? $rule->params['steps'] : [];
			if ( empty( $steps ) ) {
				return;
			}
			usort( $steps, fn( $a, $b ) => ( (int) ( $a['at'] ?? 0 ) ) <=> ( (int) ( $b['at'] ?? 0 ) ) );

			// The governing step: highest `at` ≤ upcoming.
			$governing_at = null;
			foreach ( $steps as $step ) {
				if ( (int) ( $step['at'] ?? 0 ) <= $upcoming ) {
					$governing_at = (int) $step['at'];
				}
			}

			echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
			echo '<th>' . esc_html__( 'From cycle #', 'newspack-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Pricing', 'newspack-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Price', 'newspack-plugin' ) . '</th>';
			echo '<th>' . esc_html__( 'Name', 'newspack-plugin' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $steps as $step ) {
				$at        = (int) ( $step['at'] ?? 0 );
				$calc_type = (string) ( $step['calc_type'] ?? '' );
				$value     = (float) ( $step['value'] ?? 0 );
				$marker    = ( $at === $governing_at )
					? ' <strong>' . esc_html__( '← next renewal', 'newspack-plugin' ) . '</strong>'
					: '';
				echo '<tr>';
				echo '<td>' . esc_html( (string) $at ) . ( 1 === $at ? ' <span class="description">' . esc_html__( '(purchase)', 'newspack-plugin' ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( self::calc_phrase( $calc_type, $value ) ) . '</td>';
				echo '<td>' . ( $base > 0 ? wp_kses_post( wc_price( Amount_Calculator::calculate( $calc_type, $value, $base ) ) ) : '—' ) . $marker . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<td>' . esc_html( (string) ( $step['label'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			return;
		}

		if ( 'simple_price' === $rule->strategy_id ) {
			$calc_type = (string) ( $rule->params['calc_type'] ?? '' );
			$value     = (float) ( $rule->params['value'] ?? 0 );
			$limit     = max( 0, (int) ( $rule->params['cycles_limit'] ?? 0 ) );

			echo '<p>';
			echo esc_html( self::calc_phrase( $calc_type, $value ) );
			if ( $base > 0 ) {
				echo ' (' . wp_kses_post( wc_price( Amount_Calculator::calculate( $calc_type, $value, $base ) ) ) . ')'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo ' — ';
			if ( 0 === $limit ) {
				echo esc_html__( 'applies to the purchase and every renewal.', 'newspack-plugin' );
			} else {
				printf(
					/* translators: %d: number of cycles */
					esc_html( _n( 'applies to the first %d cycle, then regular price.', 'applies to the first %d cycles, then regular price.', $limit, 'newspack-plugin' ) ),
					(int) $limit
				);
			}
			echo '</p>';
		}
	}

	/**
	 * The engine's verdict for the upcoming renewal — resolved through the
	 * real pipeline (snapshot + any Always-current rules, composed), so what
	 * this line says is what the next `payment_complete` will write. Display
	 * code: any failure degrades to silence, never a broken edit screen.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	private static function render_next_renewal( $sub ): void {
		try {
			$surface = Pricing_Engine::instance()->surface( 'subscription' );
			if ( ! $surface ) {
				return; // Engine not wired — claim nothing.
			}
			$upcoming = self::upcoming_cycle( $sub );
			$decision = Pricing_Engine::instance()->resolve( $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP ) );

			echo '<p>';
			if ( $decision ) {
				printf(
					/* translators: 1: cycle number, 2: formatted price, 3: rule id */
					esc_html__( 'Next renewal (cycle %1$d) resolves to %2$s (rule #%3$s).', 'newspack-plugin' ),
					(int) $upcoming,
					wp_kses_post( wc_price( $decision->amount ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( (string) $decision->rule_id )
				);
			} else {
				printf(
					/* translators: %d: cycle number */
					esc_html__( 'Next renewal (cycle %d) resolves to the regular price.', 'newspack-plugin' ),
					(int) $upcoming
				);
			}
			echo '</p>';
		} catch ( \Throwable $e ) {
			// Engine not wired or context unbuildable — omit the line.
			return;
		}
	}

	/**
	 * Human phrasing for a calc type + value, matching the rule edit screen's
	 * vocabulary.
	 */
	private static function calc_phrase( string $calc_type, float $value ): string {
		switch ( $calc_type ) {
			case Amount_Calculator::FIXED_PRICE:
				/* translators: %s: formatted price */
				return sprintf( __( 'Set price to %s', 'newspack-plugin' ), wp_strip_all_tags( html_entity_decode( wc_price( $value ), ENT_QUOTES ) ) );
			case Amount_Calculator::PERCENT_OF_BASE:
				/* translators: %s: percentage */
				return sprintf( __( '%s%% of regular price', 'newspack-plugin' ), rtrim( rtrim( number_format( $value, 2 ), '0' ), '.' ) );
			case Amount_Calculator::DISCOUNT_FIXED:
				/* translators: %s: formatted amount */
				return sprintf( __( '%s off regular price', 'newspack-plugin' ), wp_strip_all_tags( html_entity_decode( wc_price( $value ), ENT_QUOTES ) ) );
			default:
				return $calc_type;
		}
	}

	/**
	 * Upcoming cycle = completed payments + 1, mirroring the renewal surface.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 */
	private static function upcoming_cycle( $sub ): int {
		$completed = is_callable( [ $sub, 'get_payment_count' ] ) ? (int) $sub->get_payment_count( 'completed' ) : 0;
		return $completed + 1;
	}

	/**
	 * First product line item — multi-line subscriptions are excluded from
	 * dynamic pricing upstream, so "first" is "the" line.
	 *
	 * @param \WC_Subscription $sub Subscription.
	 * @return \WC_Order_Item_Product|null
	 */
	private static function recurring_line( $sub ) {
		foreach ( $sub->get_items() as $item ) {
			return $item;
		}
		return null;
	}

	/**
	 * Resolve a subscription from whatever the add_meta_boxes hook supplied.
	 *
	 * @param mixed $post_or_order WP_Post (legacy screen) or order object (HPOS).
	 * @return \WC_Subscription|null
	 */
	private static function resolve_subscription( $post_or_order ) {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return null;
		}
		$id = 0;
		if ( $post_or_order instanceof \WP_Post ) {
			$id = (int) $post_or_order->ID;
		} elseif ( is_object( $post_or_order ) && is_callable( [ $post_or_order, 'get_id' ] ) ) {
			$id = (int) $post_or_order->get_id();
		}
		$sub = $id ? wcs_get_subscription( $id ) : false;
		return $sub ? $sub : null;
	}
}
