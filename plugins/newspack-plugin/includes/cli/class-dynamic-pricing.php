<?php
/**
 * Dynamic Pricing CLI — deal migration (docs 03 §5).
 *
 * Retroactivity as a named operation: policy edits never reach existing
 * subscriptions implicitly; this command is the deliberate way to re-pin a
 * fleet to a policy's current config, or to detach a deal entirely.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Dynamic_Pricing\Amount_Calculator;
use Newspack\Dynamic_Pricing\Policy;
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
	 * Migrate existing subscriptions against a policy: re-pin them to the
	 * policy's current config (default), or detach the policy's pins.
	 *
	 * ## OPTIONS
	 *
	 * --policy=<id>
	 * : The shop_pricing_policy post id.
	 *
	 * [--subscription=<id>]
	 * : Limit to a single subscription.
	 *
	 * [--detach]
	 * : Instead of re-pinning, remove this policy's pins and restore the
	 * catalog price on the affected line items.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack dynamic-pricing migrate --policy=18 --dry-run
	 *     wp newspack dynamic-pricing migrate --policy=18
	 *     wp newspack dynamic-pricing migrate --policy=18 --subscription=191
	 *     wp newspack dynamic-pricing migrate --policy=18 --detach
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 */
	public function migrate( $args, $assoc_args ): void {
		if ( ! class_exists( 'WC_Subscriptions' ) || ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is required.' );
		}

		$policy_id = (int) ( $assoc_args['policy'] ?? 0 );
		$dry_run   = isset( $assoc_args['dry-run'] );
		$detach    = isset( $assoc_args['detach'] );
		if ( $policy_id <= 0 ) {
			WP_CLI::error( 'A --policy id is required.' );
		}

		$policy = null;
		$post   = get_post( $policy_id );
		if ( $post && 'shop_pricing_policy' === $post->post_type ) {
			$policy = Policy::from_post( $post );
		}
		if ( ! $detach ) {
			// Re-pin needs a real, published, deal-class policy to snapshot from.
			if ( ! $policy || 'publish' !== $post->post_status ) {
				WP_CLI::error( "Policy {$policy_id} not found or not published. (--detach works without the policy row.)" );
			}
			if ( Policy::APPLICATION_DEAL !== $policy->application ) {
				WP_CLI::error( "Policy {$policy_id} is live-class; live policies apply at every renewal and are never pinned." );
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
				? $this->detach_one( $sub, $policy_id, $dry_run )
				: $this->repin_one( $sub, $policy, $engine, $surface, $dry_run );
			$counts[ $changed ? 'changed' : 'skipped' ]++;
		}

		$verb = $detach ? 'detached' : 're-pinned';
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
	 * Re-pin one subscription: write the policy's current snapshot onto the
	 * recurring line item and immediately reprice the upcoming cycle through
	 * the engine (idempotent apply; audit notes land as usual).
	 *
	 * @param \WC_Subscription     $sub     Subscription.
	 * @param Policy               $policy  Deal-class policy to pin.
	 * @param Pricing_Engine       $engine  Engine.
	 * @param Subscription_Surface $surface Renewal surface.
	 * @param bool                 $dry_run Report only.
	 * @return bool Whether the subscription was (or would be) changed.
	 */
	private function repin_one( \WC_Subscription $sub, Policy $policy, Pricing_Engine $engine, Subscription_Surface $surface, bool $dry_run ): bool {
		$lines = $sub->get_items( 'line_item' );
		if ( 1 !== count( $lines ) ) {
			WP_CLI::log( sprintf( '#%d: skipped (multi-line subscriptions are excluded).', $sub->get_id() ) );
			return false;
		}
		$line    = reset( $lines );
		$product = wc_get_product( $line->get_variation_id() ?: $line->get_product_id() );
		if ( ! $product || ! $policy->matches_product( $product, $engine ) ) {
			WP_CLI::log( sprintf( '#%d: skipped (out of policy scope).', $sub->get_id() ) );
			return false;
		}

		$ctx = $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );

		if ( $dry_run ) {
			// Preview what the pinned config would decide for the upcoming cycle
			// (strategy-level, pre-guardrails — the post-pin engine result).
			$preview  = null;
			$strategy = $engine->strategy( $policy->strategy_id );
			if ( $strategy && $strategy->applies_to( $ctx, $policy->params ) ) {
				$preview = $strategy->decide( $ctx, $policy->params );
			}
			WP_CLI::log( sprintf(
				'#%d: would pin policy %s; upcoming cycle %d would resolve to %s (line currently %s).',
				$sub->get_id(),
				$policy->id,
				(int) $ctx->signals['completed_cycles'],
				$preview ? number_format( $preview->amount, 2 ) : '(no decision)',
				number_format( (float) $line->get_subtotal(), 2 )
			) );
			return true;
		}

		Subscription_Pin::pin( $line, $policy );
		$sub->add_order_note(
			sprintf(
				/* translators: 1: policy id */
				__( 'Newspack Dynamic Pricing [policy %1$s]: deal pinned via migration — renewals follow this policy as configured at migration time.', 'newspack-plugin' ),
				$policy->id
			)
		);

		// Reprice the upcoming cycle now (post-pin resolution includes the snapshot).
		$ctx = $surface->context( $sub, Subscription_Surface::TRIGGER_SCHEDULED_STEP );
		$d   = $engine->resolve( $ctx );
		if ( $d ) {
			$surface->apply( $ctx, $d );
		}
		WP_CLI::log( sprintf( '#%d: pinned policy %s.', $sub->get_id(), $policy->id ) );
		return true;
	}

	/**
	 * Detach one subscription: remove this policy's pin and restore the catalog
	 * price on the recurring line item.
	 *
	 * @param \WC_Subscription $sub       Subscription.
	 * @param int              $policy_id Policy id whose pins to remove.
	 * @param bool             $dry_run   Report only.
	 * @return bool Whether the subscription was (or would be) changed.
	 */
	private function detach_one( \WC_Subscription $sub, int $policy_id, bool $dry_run ): bool {
		foreach ( $sub->get_items( 'line_item' ) as $line ) {
			$snapshot = Subscription_Pin::snapshot( $line );
			if ( ! $snapshot || (string) $policy_id !== (string) ( $snapshot['policy_id'] ?? '' ) ) {
				continue;
			}

			$product = wc_get_product( $line->get_variation_id() ?: $line->get_product_id() );
			$base    = $product ? Amount_Calculator::base_price_for( $product ) : 0.0;
			$qty     = max( 1, (int) $line->get_quantity() );

			if ( $dry_run ) {
				WP_CLI::log( sprintf(
					'#%d: would detach policy %d%s.',
					$sub->get_id(),
					$policy_id,
					$base > 0 ? sprintf( ' and restore catalog price %s', number_format( $base * $qty, 2 ) ) : ''
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
					/* translators: 1: policy id, 2: formatted price */
					__( 'Newspack Dynamic Pricing [policy %1$s]: deal detached via migration; recurring price restored to catalog (%2$s).', 'newspack-plugin' ),
					$policy_id,
					wc_price( $base * $qty )
				)
			);
			$sub->save();
			WP_CLI::log( sprintf( '#%d: detached policy %d.', $sub->get_id(), $policy_id ) );
			return true;
		}
		return false;
	}
}
