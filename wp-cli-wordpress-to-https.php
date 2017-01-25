<?php
namespace WP_CLI_WordPressToSSL;

use \WP_CLI;
/**
 * Plugin Name: WordPress to HTTPS
 * Plugin URI:  https://madalin.eu
 * Description: Quickly move WordPress sites to HTTPS, also updating .htaccess
 * Version:     1.0.8
 * Author:      Madalin Tache
 * Author URI:  https://madalin.eu
 * Donate link: https://ko-fi.com/A204JA0
 * License:     GPLv3
 *
 * @link    https://github.com/niladam/wp-cli-wordpress-to-https
 * @package WP CLI WordPress to HTTPS
 */

/**
 * Copyright (c) 2017 Madalin Tache (madalin@madalin.eu)
 *
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
if ( ! class_exists( 'WordPress_To_SSL' ) ) {
	class WordPress_To_SSL
	{
		private $home_dir;
		private $siteurl;
		private $htaccess;
		private $htaccess_file;
		private $old_url;
		private $new_url;
		private $old_htaccess;

		/**
		 * Returns true if all requirements are met.
		 *
		 * @return bool
		 */
		public function verify_requirements()
		{
			$this->home_dir = getcwd();
			$this->siteurl = get_option( 'siteurl' );
			if ( $this->siteurl === '') {
				WP_CLI::error( 'I couldn\'t get your site\'s URL. Exiting..' );
			}

			if ( ! is_writable($this->home_dir) ) {
				WP_CLI::error( 'Your WordPress root folder is not writeable. Bailing.' );
			}

			static::debug("{$this->home_dir} is writable ... OK");

			if ( parse_url($this->siteurl, PHP_URL_SCHEME) === 'https') {
				WP_CLI::error( 'Your website is already using HTTPS. There\'s no need to use this package.' );
			}
			static::debug("{$this->siteurl} is not using HTTPS ... OK");
			return true;
		}

		/**
		 * Return a pretty human readable time to be used in the
		 * filename of the exported database and archive.
		 */
		public function file_timestamp()
		{
			return date( "Y-m-d-Hi", time() );
		}

		/**
		 * Starts the conversion procedure.
		 */
		public function start_conversion( $args )
		{
			if ( ! $this->verify_requirements() ) {
				exit;
			}

			$domain_name = parse_url($this->siteurl, PHP_URL_HOST);
			$exportname = $domain_name . '-' . $this->file_timestamp() . '.sql';
			$args[0] = $exportname;
			$options = array(
				'return'     => 'all',
				'parse'      => 'json',
				'launch'     => true,
				'exit_error' => true,
				);
			$run_export = WP_CLI::runcommand('db export '.$exportname.' ', $options);
			if ( 0 === $run_export->return_code ) {
				WP_CLI::success( "Database saved as {$exportname}");
				static::debug( "Database dumped ... OK" );
				$this->create_archive($exportname);
			} else {
				WP_CLI::error( "Database export failed. Exiting.", 'wp2ssl' );
				exit;
			}
		}

		/**
		 * Attempts to create a zip archive of the exported database.
		 *
		 */
		public function create_archive( $exported_file )
		{
			$archive_filename = $exported_file . '.zip';
			$cmd = "zip {$archive_filename} {$exported_file}";

			static::debug( "Attempting database compression using ZIP." );

			$ret = WP_CLI::launch( escapeshellcmd( $cmd ), false, true );
			if ( 0 === $ret->return_code ) {
				$filename = pathinfo( $archive_filename, PATHINFO_BASENAME );
				WP_CLI::success( "Database retained and archived as {$filename}", 'wp2ssl' );
				WP_CLI::debug( "Database compression ... OK", 'wp2ssl');
				$this->update_urls();
			} else {
				$error = $ret->stderr ? $ret->stderr : $ret->stdout;
				WP_CLI::error( $error );
			}
		}

		/**
		 * Runs search and replace on the current database, replacing
		 * all instances of HTTP with HTTPS.
		 * */
		public function update_urls()
		{
			$this->old_url = $this->siteurl;
			$this->new_url = preg_replace("/^http:/i", "https:", $this->old_url);
			$args = array($this->old_url, $this->new_url);

			static::debug( "Updating the database replacing {$this->old_url} with {$this->new_url}..." );
			$assoc_args = array( 'precise' => true, 'verbose' => true );
			$search_replace = \WP_CLI::launch_self(
				"search-replace",
				array(
					$this->old_url,
					$this->new_url,
					),
				array(),
				false,
				false,
				$assoc_args
				);

			if ( 0 === $search_replace ) {
				WP_CLI::success( "Updated database and replaced {$this->old_url} with {$this->new_url}" );
				$this->update_htaccess_file();
			} else {
				WP_CLI::error( 'Search and replace failed. Exiting.' );
				exit;
			}
		}

		/**
		 * Updates the .htaccess file with force to HTTPS rules,
		 * as seen below.
		 *
		 */
		public function update_htaccess_file()
		{
			$this->htaccess = <<<EOF
# WP2SSL start
# Forcing HTTP to HTTPs
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
# WP2SSL end
EOF;
			if ( $this->backup_htaccess() ) {
			$this->htaccess_file = $this->home_dir . '/.htaccess';
			$this->old_htaccess = file_get_contents ($this->htaccess_file);
			file_put_contents ($this->htaccess_file, $this->htaccess . "\n" . $this->old_htaccess);

			WP_CLI::success( "Your .htaccess file has been updated" );
			WP_CLI::success( "The original has been saved as {$this->file_timestamp}" );
			WP_CLI::success( "Your site should now be available as {$this->new_url}" );
			WP_CLI::success( "Enjoy :)" );
			}
		}

		/**
		 * Checks for the existence of .htaccess and attempts to either
		 * create it (if missing) or retaining it's current data into
		 * .htaccess.timestamp
		 *
		 * @return bool
		 * */
		public function backup_htaccess()
		{
			if ( ! file_exists($this->htaccess_file) ) {
				WP_CLI::warning( ".htaccess file is missing, it will be created.");
				return true;
			}


			if ( ! copy($this->htaccess_file, $this->home_dir . '/.htacces.' . $this->file_timestamp)) {
				WP_CLI::warning( "Your original .htaccess could not be backed up to {$this->file_timestamp}" );
				WP_CLI::warning( "However, the new rules will be added at the top." );
			}

			if ( !is_writable( $this->htaccess_file ) ) {
				WP_CLI::warning( "Your .htaccess file is not writable." );
				WP_CLI::warning( "You have to manually update your .htaccess" );
				WP_CLI::warning( "By adding the following at the top:" );
				WP_CLI::warning( "# WP2SSL start " );
				WP_CLI::warning( "# Forcing HTTP to HTTPs" );
				WP_CLI::warning( "RewriteEngine On" );
				WP_CLI::warning( "RewriteCond %{HTTPS} off" );
				WP_CLI::warning( "RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]" );
				WP_CLI::warning( "# WP2SSL end");
				WP_CLI::warning( "If for any reason this can't be done, you'll");
				WP_CLI::warning( "have to restore the database manually.");
				WP_CLI::error( "Could not write to .htaccess. Exiting.." );
			}

			return true;

			}

		/**
		 * Provide parameters to the migrate-to-ssl command.
		 * This was borrowed and therefore for now i'm unsure about it.
		 *
		 * @author binarygary https://github.com/binarygary
		 */
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

		/**
		 * Log to debug.
		 *
		 * @author aaemnnosttv (https://github.com/aaemnnosttv)
		 *
		 * @param $message
		 */
		private static function debug($message)
		{
			WP_CLI::debug($message, 'wp2ssl');
		}
	}

	/**
	 * Kick off!
	 *
	 * @return WordPress_To_SSL
	 */
	function wp_to_ssl()
	{
		return new WordPress_To_SSL;
	}

	$convert_site_to_ssl = wp_to_ssl();

	/**
	 * Register wp2ssl as a WP CLI command.
	 */
	WP_CLI::add_command( 'migrate-to-ssl', array(
			$convert_site_to_ssl,
			'start_conversion',
		), $convert_site_to_ssl->get_command_args());

	}
