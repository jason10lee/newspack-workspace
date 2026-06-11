<?php
/**
 * Dynamic Pricing CLI — rule migration (docs 03 §5).
 *
 * Retroactivity as a named operation: rule edits never reach existing
 * subscriptions implicitly; this command is the deliberate way to re-pin a
 * fleet to a rule's current config, or to release a rule entirely.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Pricing_Rule;
use Newspack\Dynamic_Pricing\Pricing_Engine;
use Newspack\Dynamic_Pricing\Subscription_Pin;
use Newspack\Dynamic_Pricing\Subscriptions\Subscription_Surface;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic Pricing CLI commands.
 */
class Dynamic_Pricing_CLI {
	/**
	 * Migrate existing subscriptions against a pricing rule: lock them to the
	 * rule's current config (default), or release the rule from existing subs.
	 *
	 * ## OPTIONS
	 *
	 * --rule=<id>
	 * : The pricing-rule post id (CPT slug remains shop_pricing_rule for
	 * developer-API compatibility).
	 *
	 * [--subscription=<id>]
	 * : Limit to a single subscription.
	 *
	 * [--detach]
	 * : Instead of re-locking, release this rule from existing subscriptions
	 * and restore the regular price on the affected line items.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack dynamic-pricing migrate --rule=18 --dry-run
	 *     wp newspack dynamic-pricing migrate --rule=18
	 *     wp newspack dynamic-pricing migrate --rule=18 --subscription=191
	 *     wp newspack dynamic-pricing migrate --rule=18 --detach
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function migrate( $args, $assoc_args ): void {
		if ( ! class_exists( 'WC_Subscriptions' ) || ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is required.' );
		}

		$rule_id = (int) ( $assoc_args['rule'] ?? 0 );
		$dry_run   = isset( $assoc_args['dry-run'] );
		$detach    = isset( $assoc_args['detach'] );
		if ( $rule_id <= 0 ) {
			WP_CLI::error( 'A --rule id is required.' );
		}

		$rule = null;
		$post   = get_post( $rule_id );
		if ( $post && 'shop_pricing_rule' === $post->post_type ) {
			$rule = Pricing_Rule::from_post( $post );
		}
		if ( ! $detach ) {
			// Re-lock needs a real, published, locked-application rule to snapshot from.
			if ( ! $rule || 'publish' !== $post->post_status ) {
				WP_CLI::error( "Pricing rule {$rule_id} not found or not published. (--detach works without the rule row.)" );
			}
			if ( Pricing_Rule::APPLICATION_LOCKED !== $rule->application ) {
				WP_CLI::error( "Pricing rule {$rule_id} is set to Always current; current-mode rules apply at every renewal and are never locked onto subscribers." );
			}
		}

		$engine  = Pricing_Engine::instance();
		$surface = $engine->surface( 'subscription' );
		if ( ! $surface instanceof Subscription_Surface ) {
			WP_CLI::error( 'Subscription surface is not registered.' );
		}

		$counts = [ 'examined' => 0, 'changed' => 0, 'skipped' => 0 ];
		foreach ( $this->subscriptions( $assoc_args ) as $sub ) {
			$counts['examined']++;
			$changed = $detach
				? $this->detach_one( $sub, $rule_id, $dry_run )
				: $this->repin_one( $sub, $rule, $engine, $surface, $dry_run );
			$counts[ $changed ? 'changed' : 'skipped' ]++;
		}

		$verb = $detach ? 'released' : 'locked';
		WP_CLI::success(
			sprintf(
				'%s%d subscriptions examined: %d %s, %d skipped.',
				$dry_run ? '[dry-run] ' : '',
				$counts['examined'],
				$counts['changed'],
				$verb,
				$counts['skipped']
			)
		);
	}

	/**
	 * Yield candidate subscriptions, paged.
	 *
	 * @param array $assoc_args Command args.
	 * @return \Generator<\WC_Subscription>
	 */
	private function subscriptions( array $assoc_args ): \Generator {
		$single = (int) ( $assoc_args['subscription'] ?? 0 );
		if ( $single > 0 ) {
			$sub = wcs_get_subscription( $single );
			if ( ! $sub ) {
				WP_CLI::error( "Subscription {$single} not found." );
			}
			yield $sub;
			return;
		}

		$page = 1;
		$seen = [];
		do {
			$batch = wcs_get_subscriptions( [
				'subscriptions_per_page' => 50,
				'paged'                  => $page,
				'subscription_status'    => [ 'active', 'on-hold' ],
			] );
			$fresh = 0;
			foreach ( $batch as $sub ) {
				// Guard against storage backends where pagination repeats results.
				if ( isset( $seen[ $sub->get_id() ] ) ) {
					continue;
				}
				$seen[ $sub->get_id() ] = true;
				$fresh++;
				yield $sub;
			}
			$page++;
		} while ( ! empty( $batch ) && $fresh > 0 );
	}

	/**
	 * Re-pin one subscription: write the rule's current snapshot onto the
	 * recurring line item and immediately reprice the upcoming cycle through
	 * the engine (idempotent apply; audit notes land as usual).
	 *
	 * @param \WC_Subscription     $sub     Subscription.
	 * @param Pricing_Rule                 Deal-class rule to pin.
	 * @param Pricing_Engine       $engine  Engine.
	 * @param Subscription_Surface $surface Renewal surface.
	 * @param bool                 $dry_run Report only.
	 * @return bool Whether the subscription was (or would be) changed.
	 */
	private function repin_one( \WC_Subscription $sub, Pricing_Rule $rule, Pricing_Engine $engine, Subscription_Surface $surface, bool $dry_run ): bool {
		$lines = $sub->get_items( 'line_item' );
		if ( 1 !== count( $lines ) ) {
			WP_CLI::log( sprintf( '#%d: skipped (multi-line subscriptions are excluded).', $sub->get_id() ) );
			return false;
		}
		$line    = reset( $lines );
		$product = wc_get_product( $line->get_variation_id() ?: $line->get_product_id() );
		if ( ! $product || ! $rule->matches_product( $product, $engine ) ) {
			WP_CLI::log( sprintf( '#%d: skipped (out of rule scope).', $sub->get_id() ) );
			return false;
		}

		$ctx = $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );

		if ( $dry_run ) {
			// Preview what the pinned config would decide for the upcoming cycle
			// (strategy-level, pre-guardrails — the post-pin engine result).
			$preview  = null;
			$strategy = $engine->strategy( $rule->strategy_id );
			if ( $strategy && $strategy->applies_to( $ctx, $rule->params ) ) {
				$preview = $strategy->decide( $ctx, $rule->params );
			}
			WP_CLI::log( sprintf(
				'#%d: would lock rule %s; upcoming cycle %d would resolve to %s (line currently %s).',
				$sub->get_id(),
				$rule->id,
				(int) $ctx->signals['completed_cycles'],
				$preview ? number_format( $preview->amount, 2 ) : '(no decision)',
				number_format( (float) $line->get_subtotal(), 2 )
			) );
			return true;
		}

		Subscription_Pin::pin( $line, $rule );
		$sub->add_order_note(
			sprintf(
				/* translators: 1: rule id */
				__( 'Newspack Dynamic Pricing [rule %1$s]: terms locked via migration — renewals follow this rule as configured at migration time.', 'newspack-plugin' ),
				$rule->id
			)
		);

		// Reprice the upcoming cycle now (post-pin resolution includes the snapshot).
		$ctx = $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$d   = $engine->resolve( $ctx );
		if ( $d ) {
			$surface->apply( $ctx, $d );
		}
		WP_CLI::log( sprintf( '#%d: locked rule %s.', $sub->get_id(), $rule->id ) );
		return true;
	}

	/**
	 * Detach one subscription: remove this rule's pin and restore the catalog
	 * price on the recurring line item.
	 *
	 * @param \WC_Subscription $sub       Subscription.
	 * @param int              $rule_id Pricing_Rule id whose pins to remove.
	 * @param bool             $dry_run   Report only.
	 * @return bool Whether the subscription was (or would be) changed.
	 */
	private function detach_one( \WC_Subscription $sub, int $rule_id, bool $dry_run ): bool {
		foreach ( $sub->get_items( 'line_item' ) as $line ) {
			$snapshot = Subscription_Pin::snapshot( $line );
			if ( ! $snapshot || (string) $rule_id !== (string) ( $snapshot['rule_id'] ?? '' ) ) {
				continue;
			}

			$product = wc_get_product( $line->get_variation_id() ?: $line->get_product_id() );
			$base    = $product ? Amount_Calculator::base_price_for( $product ) : 0.0;
			$qty     = max( 1, (int) $line->get_quantity() );

			if ( $dry_run ) {
				WP_CLI::log( sprintf(
					'#%d: would release rule %d%s.',
					$sub->get_id(),
					$rule_id,
					$base > 0 ? sprintf( ' and restore regular price %s', number_format( $base * $qty, 2 ) ) : ''
				) );
				return true;
			}

			Subscription_Pin::unpin( $line );
			if ( $base > 0 ) {
				$line->set_subtotal( round( $base * $qty, 2 ) );
				$line->set_total( round( $base * $qty, 2 ) );
				$line->save();
				$sub->calculate_totals();
			}
			$sub->add_order_note(
				sprintf(
					/* translators: 1: rule id, 2: formatted price */
					__( 'Newspack Dynamic Pricing [rule %1$s]: rule released via migration; recurring price restored to regular (%2$s).', 'newspack-plugin' ),
					$rule_id,
					wc_price( $base * $qty )
				)
			);
			$sub->save();
			WP_CLI::log( sprintf( '#%d: released rule %d.', $sub->get_id(), $rule_id ) );
			return true;
		}
		return false;
	}
}
