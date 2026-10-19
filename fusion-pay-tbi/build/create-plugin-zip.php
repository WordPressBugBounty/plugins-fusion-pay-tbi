<?php
/**
 * Build script: creates fusion-pay-tbi.zip for distribution.
 * Run from the build/ directory: php create-plugin-zip.php
 */

$plugin_dir  = dirname( __DIR__ );
$plugin_name = 'fusion-pay-tbi';
$output_file = $plugin_dir . '/' . $plugin_name . '.zip';

$include = array(
    'fusion-pay-tbi.php',
    'uninstall.php',
    'readme.txt',
    'assets',
    'src',
    'includes',
    'languages',
);

if ( file_exists( $output_file ) ) {
    unlink( $output_file );
}

$zip = new ZipArchive();
if ( $zip->open( $output_file, ZipArchive::CREATE ) !== true ) {
    die( "Cannot create $output_file\n" );
}

foreach ( $include as $item ) {
    $full_path = $plugin_dir . '/' . $item;
    if ( is_file( $full_path ) ) {
        $zip->addFile( $full_path, $plugin_name . '/' . $item );
    } elseif ( is_dir( $full_path ) ) {
        add_directory_to_zip( $zip, $full_path, $plugin_name . '/' . $item );
    }
}

$zip->close();
echo "Created: $output_file\n";

function add_directory_to_zip( ZipArchive $zip, $dir, $zip_path ) {
    $items = scandir( $dir );
    foreach ( $items as $item ) {
        if ( $item === '.' || $item === '..' ) {
            continue;
        }
        $full_path = $dir . '/' . $item;
        $zip_entry = $zip_path . '/' . $item;
        if ( is_file( $full_path ) ) {
            $zip->addFile( $full_path, $zip_entry );
        } elseif ( is_dir( $full_path ) ) {
            add_directory_to_zip( $zip, $full_path, $zip_entry );
        }
    }
}
