/**
 * Check if a gate's content rules match the current post state. Mirrors
 * the PHP `Content_Restriction_Control::get_post_gates()` evaluator.
 *
 * @param {Array}  contentRules Gate content rules.
 * @param {string} postType     Current post type.
 * @param {Object} termsByTax   Map of taxonomy REST base to array of term IDs.
 * @param {number} postId       Current post ID, for the specific_posts override.
 * @param {string} matchMode    'all' (AND, default) or 'any' (OR).
 * @param {Object} taxonomyMap  Map of taxonomy slug to REST base.
 *
 * @return {boolean} Whether the gate applies to this post.
 */
export function gateMatchesPost( contentRules, postType, termsByTax, postId, matchMode = 'all', taxonomyMap = {} ) {
	// Inclusion override: if this post ID is listed in any specific_posts
	// rule, the gate applies regardless of other rules.
	const specificMatch = contentRules.some(
		rule =>
			rule.slug === 'specific_posts' &&
			Array.isArray( rule.value ) &&
			rule.value.length > 0 &&
			rule.value.map( v => parseInt( v ) ).includes( parseInt( postId ) )
	);
	if ( specificMatch ) {
		return true;
	}

	const ruleMatches = rule => {
		const isExclusion = rule.exclusion;
		if ( rule.slug === 'post_types' ) {
			return isExclusion ? ! rule.value.includes( postType ) : rule.value.includes( postType );
		}
		const restBase = taxonomyMap[ rule.slug ];
		if ( ! restBase ) {
			return false;
		}
		const postTerms = termsByTax[ restBase ] || [];
		if ( ! isExclusion && ! postTerms.length ) {
			return false;
		}
		const hasOverlap = rule.value.some( id => postTerms.includes( parseInt( id ) ) );
		return isExclusion ? ! hasOverlap : hasOverlap;
	};

	const nonSpecific = contentRules.filter( rule => rule.slug !== 'specific_posts' );
	if ( nonSpecific.length === 0 ) {
		return false;
	}
	return matchMode === 'any' ? nonSpecific.some( ruleMatches ) : nonSpecific.every( ruleMatches );
}
