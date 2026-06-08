/**
 * Audience › Geographic (NPPD-1649, Section 5).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import MetricTable from '../../components/MetricTable';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const READERS_COL = { key: 'readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number' as const, align: 'right' as const };

const GeographicSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-geo">
		<h2 id="newspack-insights-audience-geo" className="newspack-insights__section-heading">
			{ __( 'Geographic', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'Where your readers are.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Countries', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_countries }
					emptyMessage={ __( 'No data in this timeframe.', 'newspack-plugin' ) }
					columns={ [ { key: 'country', label: __( 'Country', 'newspack-plugin' ) }, READERS_COL ] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Regions / States', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_regions }
					emptyMessage={ __( 'No data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'country', label: __( 'Country', 'newspack-plugin' ) },
						{ key: 'region', label: __( 'Region', 'newspack-plugin' ) },
						READERS_COL,
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Cities', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_cities }
					emptyMessage={ __( 'No data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'country', label: __( 'Country', 'newspack-plugin' ) },
						{ key: 'region', label: __( 'Region', 'newspack-plugin' ) },
						{ key: 'city', label: __( 'City', 'newspack-plugin' ) },
						READERS_COL,
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top DMAs', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_dmas }
					emptyMessage={ __( 'No DMA data (US-only) in this timeframe.', 'newspack-plugin' ) }
					columns={ [ { key: 'dma', label: __( 'DMA', 'newspack-plugin' ) }, READERS_COL ] }
				/>
			</div>
		</div>
	</section>
);

export default GeographicSection;
