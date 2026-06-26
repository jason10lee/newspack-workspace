/**
 * Tab-scoped "ship callback" config (NPPD-1728).
 *
 * Closes the feedback loop: when we ship a change a publisher asked for on a
 * given tab, we surface a dismissible top-of-tab callout — "You asked for X on
 * this tab; we just shipped it." Research says this is the single thing that
 * sustains response rate, so the plumbing is built now and sits ready.
 *
 * The registry is intentionally EMPTY at launch — nothing has shipped from
 * feedback yet, so no callout fires. When the first requested change ships,
 * add an entry: `tab -> { id, heading, body }`. The `id` namespaces the
 * per-tab dismissal so a later callback re-announces rather than inheriting an
 * old dismissal. Each entry's heading/body is authored when the change ships.
 */

/**
 * Internal dependencies
 */
import type { TabKey } from '../components/InsightsWizard';

export interface ShipCallback {
	/** Stable id; namespaces the per-tab dismissal so a new callback re-shows. */
	id: string;
	/** Bold heading for the callout. */
	heading: string;
	/** Body copy. */
	body: string;
}

/**
 * Per-tab ship-callback registry. Empty until the first feedback-driven change
 * ships.
 */
const CALLBACKS: Partial< Record< TabKey, ShipCallback > > = {};

/**
 * The ship callback to surface on a tab, or null when there's nothing to
 * announce.
 *
 * @param tab Tab id.
 * @return The callback, or null.
 */
export const getShipCallback = ( tab: TabKey ): ShipCallback | null => CALLBACKS[ tab ] ?? null;
