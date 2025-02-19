<?php

/**
 * Check if the given directory is compatible with VIP Go.
 *
 * @param string $directory_path The directory path to check.
 *
 * @return string 'Yes' if compatible, otherwise an error message.
 */
function check_vip_compatibility( $directory_path ) {
    if ( ! is_dir( $directory_path ) || ! is_readable( $directory_path ) ) {
        return 'Error: Directory not found or unreadable';
    }

    // Get all PHP files in the directory recursively
    $php_files = glob( $directory_path . '/**/*.php', GLOB_BRACE );

    if ( empty( $php_files ) ) {
        return 'No PHP files found';
    }

    foreach ( $php_files as $file_path ) {
        // Run PHPCS to check for file write operations
        $phpcs_output = shell_exec( "vendor/bin/phpcs --standard=WordPress-VIP-Go --sniffs=WordPress.Filesystem $file_path" );

        // If PHPCS detects violations related to filesystem writes, analyze further
        if ( strpos( $phpcs_output, 'WordPress.Filesystem' ) !== false ) {
            $file_contents = file_get_contents( $file_path );

            // Check for write operations
            if ( preg_match( '/fopen\(|file_put_contents\(|fwrite\(|rename\(|unlink\(/', $file_contents ) ) {
                // Ensure it's not inside 'wp-content/uploads'
                if ( strpos( $file_contents, 'wp-content/uploads' ) === false ) {
                    return 'Write Operation for directory other than uploads detected';
                }
            }
        }
    }

    return 'Compatible';
}