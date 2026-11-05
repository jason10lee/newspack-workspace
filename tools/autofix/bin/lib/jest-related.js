#!/usr/bin/env node
'use strict';
// jest-related.js <file>... — run from a package directory. Runs Jest with the
// configuration `newspack-scripts test` builds, but only on tests related to
// the given files, with one worker.
//
// `newspack-scripts test <path>` cannot do this: it puts its own arguments
// after the caller's, and wp-scripts reads everything before its subcommand as
// options for Node, so a path or a Jest flag never reaches Jest and the whole
// suite runs (why that matters: "A suite guard" in tools/forge/README.md). So
// this loads newspack-scripts' test script with its spawn replaced, keeps the
// command and arguments it would have used, and starts that command itself
// with the Jest arguments after the subcommand.
//
// It refuses no files, an option, or a file that is not there. Jest runs with
// --passWithNoTests, so a mistyped path would otherwise pass with nothing run.
const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const files = process.argv.slice( 2 );
if ( files.length === 0 ) {
	console.error( 'jest-related: no files given; refusing to run the whole suite' );
	process.exit( 2 );
}
const option = files.find( ( f ) => f.startsWith( '-' ) );
if ( option ) {
	console.error( `jest-related: '${ option }' is an option, not a file; options are not passed to Jest` );
	process.exit( 2 );
}
const missing = files.filter( ( f ) => ! fs.existsSync( path.resolve( f ) ) );
if ( missing.length ) {
	console.error( `jest-related: not found in ${ process.cwd() }: ${ missing.join( ', ' ) }. Give paths relative to the plugin directory.` );
	process.exit( 2 );
}

let testScript;
try {
	testScript = require.resolve( 'newspack-scripts/scripts/test.js', {
		paths: [ process.cwd(), process.env.AUTOFIX_WORKSPACE_ROOT || process.cwd() ],
	} );
} catch ( e ) {
	console.error( `jest-related: newspack-scripts is not installed for ${ process.cwd() }` );
	process.exit( 2 );
}

let captured = null;
try {
	const crossSpawn = require.resolve( 'cross-spawn', { paths: [ path.dirname( testScript ) ] } );
	require.cache[ crossSpawn ] = {
		id: crossSpawn,
		filename: crossSpawn,
		loaded: true,
		exports: {
			sync: ( command, args, options ) => {
				captured = { command, args, options };
				return { status: 0, signal: null };
			},
		},
	};
} catch ( e ) {
	console.error( 'jest-related: cannot find the cross-spawn that newspack-scripts uses' );
	process.exit( 2 );
}

const realExit = process.exit;
const realArgv = process.argv;
process.argv = [ realArgv[ 0 ], testScript ];
process.exit = () => {};
try {
	require( testScript );
} finally {
	process.exit = realExit;
	process.argv = realArgv;
}
if ( ! captured || ! Array.isArray( captured.args ) ) {
	console.error( 'jest-related: newspack-scripts did not start its test command; nothing ran' );
	process.exit( 2 );
}

const args = [ ...captured.args, '--maxWorkers=1', '--ci', '--passWithNoTests', '--findRelatedTests', ...files ];
// A .js entry point is run with this Node, as cross-spawn would through its shebang.
const [ command, commandArgs ] = /\.[cm]?js$/.test( captured.command )
	? [ process.execPath, [ captured.command, ...args ] ]
	: [ captured.command, args ];
const result = spawnSync( command, commandArgs, { ...( captured.options || {} ), stdio: 'inherit' } );
if ( result.error ) {
	console.error( `jest-related: ${ result.error.message }` );
	process.exit( 1 );
}
process.exit( result.status === null ? 1 : result.status );
