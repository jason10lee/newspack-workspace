/**
 * Admin link helpers for Insights tables.
 *
 * Builds wp-admin URLs from the `adminUrl` boot-config value so links work
 * under any wp-admin subdirectory. Falls back to a relative path if the
 * global isn't present (defensive — matches ConnectBanner's fallback).
 */

const adminUrl = (): string => window.newspackInsights?.adminUrl || '/wp-admin/';

/** Edit URL for a post/CPT (gates, prompts, products all use post.php). */
export const getPostEditUrl = ( id: number ): string => `${ adminUrl() }post.php?post=${ id }&action=edit`;
