/**
 * Tests for the PDF export helpers (NPPD-1661).
 */

/**
 * Internal dependencies
 */
import { buildPdfFilename, printCurrentTab } from './pdfExport';
import type { DateRange } from '../state/useDateRange';

const range = { preset: 'custom', start: '2026-05-20', end: '2026-06-18' } as DateRange;

describe( 'buildPdfFilename', () => {
	it( 'joins tab slug and the range bounds as <tab>-<start>_to_<end>', () => {
		expect( buildPdfFilename( 'audience', range ) ).toBe( 'audience-2026-05-20_to_2026-06-18' );
	} );
} );

describe( 'printCurrentTab', () => {
	const originalTitle = 'Insights ‹ My Site — WordPress';
	let printSpy: jest.SpyInstance;

	beforeEach( () => {
		document.title = originalTitle;
		printSpy = jest.spyOn( window, 'print' ).mockImplementation( () => undefined );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		printSpy.mockRestore();
		jest.useRealTimers();
	} );

	it( 'sets the document title to the filename before printing', () => {
		let titleAtPrint = '';
		printSpy.mockImplementation( () => {
			titleAtPrint = document.title;
		} );

		printCurrentTab( 'audience-2026-05-20_to_2026-06-18' );

		expect( printSpy ).toHaveBeenCalledTimes( 1 );
		expect( titleAtPrint ).toBe( 'audience-2026-05-20_to_2026-06-18' );
	} );

	it( 'restores the original title on afterprint', () => {
		printCurrentTab( 'audience-2026-05-20_to_2026-06-18' );
		window.dispatchEvent( new Event( 'afterprint' ) );
		expect( document.title ).toBe( originalTitle );
	} );

	it( 'restores the original title via the timeout fallback', () => {
		printCurrentTab( 'audience-2026-05-20_to_2026-06-18' );
		expect( document.title ).toBe( 'audience-2026-05-20_to_2026-06-18' );
		jest.runAllTimers();
		expect( document.title ).toBe( originalTitle );
	} );
} );
