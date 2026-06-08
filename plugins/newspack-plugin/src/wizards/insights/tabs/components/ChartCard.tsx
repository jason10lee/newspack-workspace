/**
 * ChartCard (NPPD-1649).
 *
 * Frame around a visualization (pie / line / bar) that centralizes the
 * graceful-failure states: hidden_in_v1 (renders nothing), and the shared
 * MetricNote treatment for overlay / error / not-configured. The section
 * passes the built chart as children; ChartCard renders it only when the
 * payload is computable.
 */

/**
 * Internal dependencies
 */
import MetricNote from './MetricNote';
import type { MetricPayload } from './metrics';

export interface ChartCardProps {
	title: string;
	caption?: string;
	payload?: MetricPayload;
	children: React.ReactNode;
}

const ChartCard = ( { title, caption, payload, children }: ChartCardProps ) => {
	if ( ! payload || payload.hidden_in_v1 ) {
		return null;
	}

	let body: React.ReactNode = children;
	if ( payload.overlay ) {
		body = <MetricNote overlay={ payload.overlay } />;
	} else if ( payload.error ) {
		body = <MetricNote error />;
	} else if ( payload.not_configured ) {
		body = <MetricNote notConfigured />;
	}

	return (
		<div className="newspack-insights__chart-card">
			<h3 className="newspack-insights__chart-card-title">{ title }</h3>
			{ caption && <p className="newspack-insights__chart-card-caption">{ caption }</p> }
			{ body }
		</div>
	);
};

export default ChartCard;
