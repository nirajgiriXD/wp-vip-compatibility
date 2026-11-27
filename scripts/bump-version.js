#!/usr/bin/env node
/**
 * Bumps the plugin version everywhere it is written down.
 *
 *   npm run bump -- patch     2.0.0 -> 2.0.1
 *   npm run bump -- minor     2.0.0 -> 2.1.0
 *   npm run bump -- major     2.0.0 -> 3.0.0
 *   npm run bump -- 2.3.4     set an exact version
 *
 * The header in preflight-audit.php is the source of truth for the current
 * version. Every other file must already agree with it, otherwise the script
 * stops rather than guess which one is right.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const SEMVER = /^\d+\.\d+\.\d+$/;

// Each pattern has exactly three groups: before, the version, after.
const TARGETS = [
	{ file: 'preflight-audit.php', pattern: /^(\s*\*\s*Version:\s+)(\S+)()/m },
	{ file: 'preflight-audit.php', pattern: /(define\( 'PREFLIGHT_AUDIT_VERSION', ')([^']+)(' \))/ },
	{ file: 'readme.txt', pattern: /^(Stable tag:\s+)(\S+)()/m },
	{ file: 'languages/preflight-audit.pot', pattern: /^("Project-Id-Version: Preflight Audit )([^\\]+)(\\n")/m },
	{ file: 'tests/php/bootstrap.php', pattern: /(define\( 'PREFLIGHT_AUDIT_VERSION', ')([^']+)(' \))/ },
];

function fail( message ) {
	console.error( `Error: ${ message }` );
	process.exit( 1 );
}

function read( file ) {
	return fs.readFileSync( path.join( ROOT, file ), 'utf8' );
}

function nextVersion( current, arg ) {
	if ( SEMVER.test( arg ) ) {
		return arg;
	}

	const [ major, minor, patch ] = current.split( '.' ).map( Number );

	switch ( arg ) {
		case 'major':
			return `${ major + 1 }.0.0`;
		case 'minor':
			return `${ major }.${ minor + 1 }.0`;
		case 'patch':
			return `${ major }.${ minor }.${ patch + 1 }`;
		default:
			fail( `expected patch, minor, major or an x.y.z version, got "${ arg || '' }".` );
	}
}

const arg = process.argv[ 2 ];
const header = read( 'preflight-audit.php' ).match( TARGETS[ 0 ].pattern );

if ( ! header || ! SEMVER.test( header[ 2 ] ) ) {
	fail( 'could not read an x.y.z Version from the preflight-audit.php header.' );
}

const current = header[ 2 ];
const next = nextVersion( current, arg );

if ( next === current ) {
	fail( `the version is already ${ current }.` );
}

// Check every file first, so a mismatch leaves nothing half-written.
const contents = {};

for ( const { file, pattern } of TARGETS ) {
	contents[ file ] = contents[ file ] || read( file );

	const match = contents[ file ].match( pattern );

	if ( ! match ) {
		fail( `no version found in ${ file } (${ pattern }).` );
	}

	if ( match[ 2 ] !== current ) {
		fail( `${ file } says ${ match[ 2 ] }, but the plugin header says ${ current }. Fix that first.` );
	}

	contents[ file ] = contents[ file ].replace( pattern, ( whole, before, version, after ) => before + next + after );
}

for ( const [ file, text ] of Object.entries( contents ) ) {
	fs.writeFileSync( path.join( ROOT, file ), text );
	console.log( `  updated ${ file }` );
}

console.log( `\nBumped ${ current } -> ${ next }.` );

if ( ! read( 'readme.txt' ).includes( `= ${ next } =` ) ) {
	console.log( `Remember to add "= ${ next } =" under == Changelog == in readme.txt.` );
}
