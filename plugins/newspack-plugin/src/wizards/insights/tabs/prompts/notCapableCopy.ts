/**
 * Per-intent "not capable" nudge copy for the Prompts tab (NPPD-1720).
 *
 * Shown when no active prompt contains the block a conversion metric measures.
 * Each string names the missing block exactly as it appears in the WP editor so
 * the nudge is actionable. Centralized here (rather than inline on each card)
 * because the donation and checkout strings are shared across the Paid reader
 * conversion and Revenue sections, and so the cross-tab voice-and-tone audit
 * (NPPD-1698) has a single place to review them.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const NOT_CAPABLE_COPY = {
	/** Form submission rate — capable when any form-bearing block is present. */
	formBearing: __(
		'We don’t detect a form-bearing block (registration, donation, or newsletter signup) in your active prompts. Add one to start measuring form submission rates.',
		'newspack-plugin'
	),
	registration: __(
		'We don’t detect a registration block in your active prompts. Add a Reader Registration block to a prompt to start tracking registration conversions.',
		'newspack-plugin'
	),
	newsletter: __(
		'We don’t detect a newsletter signup block in your active prompts. Add a Newsletter Subscribe block to a prompt to start tracking newsletter conversions.',
		'newspack-plugin'
	),
	// Explicitly addresses the hand-rolled-CTA case (a button/link donation prompt
	// that carries no Donate block).
	donation: __(
		'We don’t detect a donation block in your active prompts. If you’re driving donations with a custom button or link, switch to the Donate block to start tracking conversions.',
		'newspack-plugin'
	),
	checkout: __(
		'We don’t detect a checkout button in your active prompts. Add a Checkout Button block to a prompt to start tracking subscription conversions.',
		'newspack-plugin'
	),
};
