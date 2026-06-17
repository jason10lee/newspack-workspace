/**
 * Types for the Pricing Rules DataViews page.
 * Ambient (no imports/exports) — globally available, matching the audience views convention.
 */

interface PricingRuleSimpleParams {
	calc_type: string;
	value: number;
	cycles_limit: number;
	label: string;
}

interface PricingRuleStep {
	at: number;
	calc_type: string;
	value: number;
	label: string;
}

interface PricingRuleRow {
	id: number;
	deal_key: string;
	title: string;
	status: string;
	status_label: string;
	strategy_id: string;
	strategy_label: string;
	scope_type: string;
	scope_label: string;
	scope_ids: number[];
	priority: number;
	compose_mode: 'min' | 'priority_exclusive' | string;
	application: 'locked' | 'current' | string;
	publicize: boolean;
	active_from: number | null;
	active_until: number | null;
	active_state: 'active' | 'scheduled' | 'ended';
	target_conversion_pct: number | null;
	max_cancellation_pct: number | null;
	is_stepped: boolean;
	has_conditions: boolean;
	simple: PricingRuleSimpleParams | null;
	steps: PricingRuleStep[] | null;
	edit_link: string;
}

interface PricingRulesCurrency {
	code: string;
	symbol: string;
	decimals: number;
}

interface PricingRulesVocabItem {
	id: string;
	label: string;
	requires_value?: boolean;
}

interface PricingRulesResponse {
	rules: PricingRuleRow[];
	currency: PricingRulesCurrency;
	strategies: PricingRulesVocabItem[];
	scopes: PricingRulesVocabItem[];
	calc_types: { value: string; label: string }[];
}

interface Window {
	newspackAudiencePricingRules?: {
		rules_rest_path: string;
		engine_active: boolean;
	};
}
