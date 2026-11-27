#!/usr/bin/env node
/**
 * Builds the release ZIP: dist/preflight-audit-<version>.zip
 *
 *   npm run zip
 *
 * The ZIP holds the files git tracks, minus anything .distignore matches,
 * inside a top-level preflight-audit/ folder so WordPress installs it under
 * the right directory name. Untracked files (vendor/, node_modules/, notes)
 * are never shipped, even when .distignore forgets them.
 *
 * Written against Node's own zlib so it works the same on Windows, macOS and
 * Linux without a `zip` binary or an npm dependency.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );
const { execFileSync } = require( 'child_process' );

const ROOT = path.resolve( __dirname, '..' );
const SLUG = 'preflight-audit';

function fail( message ) {
	console.error( `Error: ${ message }` );
	process.exit( 1 );
}

function git( ...args ) {
	return execFileSync( 'git', args, { cwd: ROOT, encoding: 'utf8' } );
}

/**
 * Turns .distignore into matcher functions. Follows the gitignore rules the
 * file actually uses: a pattern containing a slash (other than a trailing one)
 * is anchored to the root, otherwise it matches a name at any depth; a
 * trailing slash means "this directory and everything in it"; `*` does not
 * cross a slash.
 */
function readDistignore() {
	const file = path.join( ROOT, '.distignore' );

	if ( ! fs.existsSync( file ) ) {
		return [];
	}

	return fs.readFileSync( file, 'utf8' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => line && ! line.startsWith( '#' ) )
		.map( ( line ) => {
			const directoryOnly = line.endsWith( '/' );
			let pattern = line.replace( /^\/|\/$/g, '' );
			const anchored = line.replace( /\/$/, '' ).includes( '/' );

			pattern = pattern
				.replace( /[.+^${}()|[\]\\]/g, '\\$&' )
				.replace( /\*/g, '[^/]*' )
				.replace( /\?/g, '[^/]' );

			const prefix = anchored ? '^' : '^(?:.*/)?';
			// A match on a directory takes everything under it. A file-only
			// pattern must match the whole path.
			const suffix = directoryOnly ? '/' : '(?:/|$)';
			const regex = new RegExp( prefix + pattern + suffix );

			return ( file ) => regex.test( file );
		} );
}

/** Minimal ZIP writer: deflated entries, a central directory, no zip64. */
function buildZip( entries ) {
	const chunks = [];
	const central = [];
	let offset = 0;

	// DOS time for "now", so the archive does not claim 1980.
	const now = new Date();
	const dosTime = ( now.getHours() << 11 ) | ( now.getMinutes() << 5 ) | ( now.getSeconds() >> 1 );
	const dosDate = ( ( now.getFullYear() - 1980 ) << 9 ) | ( ( now.getMonth() + 1 ) << 5 ) | now.getDate();

	for ( const { name, data } of entries ) {
		const nameBuffer = Buffer.from( name, 'utf8' );
		const compressed = zlib.deflateRawSync( data, { level: 9 } );
		const crc = zlib.crc32( data );

		const local = Buffer.alloc( 30 );
		local.writeUInt32LE( 0x04034b50, 0 );
		local.writeUInt16LE( 20, 4 ); // version needed
		local.writeUInt16LE( 0x0800, 6 ); // UTF-8 names
		local.writeUInt16LE( 8, 8 ); // deflate
		local.writeUInt16LE( dosTime, 10 );
		local.writeUInt16LE( dosDate, 12 );
		local.writeUInt32LE( crc, 14 );
		local.writeUInt32LE( compressed.length, 18 );
		local.writeUInt32LE( data.length, 22 );
		local.writeUInt16LE( nameBuffer.length, 26 );
		local.writeUInt16LE( 0, 28 );

		const record = Buffer.alloc( 46 );
		record.writeUInt32LE( 0x02014b50, 0 );
		record.writeUInt16LE( ( 3 << 8 ) | 20, 4 ); // made by: Unix, so permissions below are read
		record.writeUInt16LE( 20, 6 );
		record.writeUInt16LE( 0x0800, 8 );
		record.writeUInt16LE( 8, 10 );
		record.writeUInt16LE( dosTime, 12 );
		record.writeUInt16LE( dosDate, 14 );
		record.writeUInt32LE( crc, 16 );
		record.writeUInt32LE( compressed.length, 20 );
		record.writeUInt32LE( data.length, 24 );
		record.writeUInt16LE( nameBuffer.length, 28 );
		record.writeUInt16LE( 0, 30 ); // extra
		record.writeUInt16LE( 0, 32 ); // comment
		record.writeUInt16LE( 0, 34 ); // disk
		record.writeUInt16LE( 0, 36 ); // internal attributes
		record.writeUInt32LE( ( 0o100644 << 16 ) >>> 0, 38 ); // -rw-r--r--
		record.writeUInt32LE( offset, 42 );

		chunks.push( local, nameBuffer, compressed );
		central.push( record, nameBuffer );
		offset += local.length + nameBuffer.length + compressed.length;
	}

	const centralBuffer = Buffer.concat( central );
	const end = Buffer.alloc( 22 );
	end.writeUInt32LE( 0x06054b50, 0 );
	end.writeUInt16LE( entries.length, 8 );
	end.writeUInt16LE( entries.length, 10 );
	end.writeUInt32LE( centralBuffer.length, 12 );
	end.writeUInt32LE( offset, 16 );

	return Buffer.concat( [ ...chunks, centralBuffer, end ] );
}

if ( typeof zlib.crc32 !== 'function' ) {
	fail( `Node 20.15 or later is needed (this is ${ process.version }).` );
}

const header = fs.readFileSync( path.join( ROOT, `${ SLUG }.php` ), 'utf8' ).match( /^\s*\*\s*Version:\s+(\S+)/m );

if ( ! header ) {
	fail( `could not read the Version from ${ SLUG }.php.` );
}

const version = header[ 1 ];

if ( ! fs.readFileSync( path.join( ROOT, 'readme.txt' ), 'utf8' ).includes( `= ${ version } =` ) ) {
	console.warn( `Warning: readme.txt has no "= ${ version } =" changelog entry.` );
}

if ( git( 'status', '--porcelain', '--untracked-files=no' ).trim() ) {
	console.warn( 'Warning: tracked files have uncommitted changes; the ZIP includes them as they are on disk.' );
}

const ignored = readDistignore();
const files = git( 'ls-files', '-z' )
	.split( '\0' )
	.filter( Boolean )
	.filter( ( file ) => ! ignored.some( ( match ) => match( file ) ) )
	.filter( ( file ) => fs.existsSync( path.join( ROOT, file ) ) ) // deleted but not yet committed
	.sort();

if ( ! files.includes( `${ SLUG }.php` ) ) {
	fail( `${ SLUG }.php would not be in the ZIP; check .distignore.` );
}

const zip = buildZip( files.map( ( file ) => ( {
	name: `${ SLUG }/${ file }`,
	data: fs.readFileSync( path.join( ROOT, file ) ),
} ) ) );

const outDir = path.join( ROOT, 'dist' );
const outFile = path.join( outDir, `${ SLUG }-${ version }.zip` );

fs.mkdirSync( outDir, { recursive: true } );
fs.writeFileSync( outFile, zip );

if ( process.argv.includes( '--list' ) ) {
	files.forEach( ( file ) => console.log( `  ${ file }` ) );
}

console.log( `Built ${ path.relative( ROOT, outFile ) } (${ files.length } files, ${ ( zip.length / 1024 ).toFixed( 1 ) } KB).` );
