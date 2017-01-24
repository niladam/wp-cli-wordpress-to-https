<?php
/**
 * Plugin Name: WordPress to HTTPS
 * Plugin URI:  https://madalin.eu
 * Description: Quickly move WordPress sites to HTTPS
 * Version:     0.1.3
 * Author:      Madalin Tache
 * Author URI:  https://madalin.eu
 * Donate link: https://ko-fi.com/A204JA0
 * License:     GPLv2
 * Text Domain: wp-cli-wordpress-to-https
 * Domain Path: /languages
 *
 * @link    https://madalin.eu
 *
 * @package WP CLI WordPress to HTTPS
 */

/**
 * Copyright (c) 2017 Madalin Tache (madalin@madalin.eu)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

if ( ! ( defined('WP_CLI') && WP_CLI ) ) {
	return;
}

// if ( defined( 'WP_CLI' ) && WP_CLI )
// {
	if ( ! class_exists( 'WP2_ssl' ) )
	{
		class WP2_ssl {
			private $home_dir;
			private $siteurlpage;

			/**
			 * Returns true if all requirements are met.
			 *
			 * @return bool
			 */
			public function verify_requirements()
			{
				$this->home_dir = getcwd();
				$this->siteurlpage = \get_option( 'siteurl' );
				// WP_CLI::log(get_option( '' ));
				if ( ! is_writable($this->home_dir) )
				{
					WP_CLI::error( 'Your WordPress root folder is not writeable. Bailing.' );
				}
				if ( parse_url($this->siteurlpage, PHP_URL_SCHEME) === 'https')
				{
					WP_CLI::error( 'Your website is already using HTTPS. There\'s no need to use this package.' );
				}
				if ( $this->siteurlpage === '')
				{
					WP_CLI::error( 'Could not get the siteurlpage.' );
				}
				return true;
			}

			/**
			 * Return a pretty human readable time.
			 *
			 * @author Madalin Tache
			 *
			 * @return false | string
			 */
			public function file_timestamp()
			{
				return date( "Y-m-d-Hi", time() );
			}


			/**
			 * Saves a checkpoint of the db.
			 *
			 * @author Gary Kovar
			 *
			 * @since  0.1.0
			 */
			public function convert_to_ssl( $args )
			{
				if ( ! $this->verify_requirements() )
				{
					exit;
				}

				$domain_name = parse_url($this->siteurl, PHP_URL_HOST);
				$exportname = $domain_name . '-' . $this->file_timestamp() . '.sql';

				$args[0] = $exportname;

				$db = new DB_Command;
				$run_export = $db->export( $args, null );

				if ( 0 === $run_export )
				{
					WP_CLI::success( "Database exported to {$exportname}" );
					$this->zip_archive_file($exportname);
				} else {
					WP_CLI::error( 'Database export failed.' );
					exit;
				}
			}

			/**
			 * Attempts to create a zip archive of the export file
			 *
			 */
			public function zip_archive_file( $exported_file )
			{
				$archive_filename = $exported_file . '.zip';
				$cmd = "zip {$archive_filename} {$exported_file}";

				WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );

				$ret = WP_CLI::launch( escapeshellcmd( $cmd ), false, true );
				if ( 0 === $ret->return_code ) {
					$filename = pathinfo( $archive_filename, PATHINFO_BASENAME );
					WP_CLI::success( "Exported database archived to {$filename}" );
					$this->update_urls();
				} else {
					$error = $ret->stderr ? $ret->stderr : $ret->stdout;
					WP_CLI::error( $error );
				}
			}

			public function update_urls()
			{
				$old_url = $this->siteurl;
				$new_url = preg_replace("/^http:/i", "https:", $old_url);
				$args = array($old_url, $new_url);
				$assoc_args = array( 'precise' => true, 'verbose' => true );
				//
				$search_replace = \WP_CLI::launch_self(
					"search-replace",
					array(
						$old_url,
						$new_url,
					),
					array(),
					false,
					false,
					$assoc_args
				);
				if ( 0 === $search_replace )
				{
					WP_CLI::success( "Updated database and replaced {$old_url} with {$new_url}" );
				} else {
					WP_CLI::error( 'Search and replace failed.' );
					exit;
				}
			}

			public function get_command_args() {
				return array(
					'shortdesc' => 'Migrate from HTTP to HTTPS',
					'synopsis'  => array(
						array(
							'type'     => 'positional',
							'name'     => 'name',
							'optional' => true,
							'multiple' => false,
						),
					),
					'when'      => 'after_wp_load',
				);
			}

}

		/**
		 * Kick off!
		 *
		 * @return WP2_ssl
		 */
		function wp_to_ssl()
		{
			return new WP2_ssl;
		}

		$convert_site_to_ssl = wp_to_ssl();

		/**
		 * Add wp2ssl as a WP CLI command.
		 */
		WP_CLI::add_command( 'wp2ssl', array(
			$convert_site_to_ssl,
			'convert_to_ssl',
		), $convert_site_to_ssl->get_command_args());

	}
// }
