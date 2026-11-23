<?php
/**
 * Internal Build Engine Configuration.
 *
 * @package Bizgrowth360\AppBuilder
 */

namespace Bizgrowth360\AppBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Config
 *
 * Centralizes all internal GitHub build engine configuration.
 * Regular WordPress users never see or edit these settings.
 */
class Config {

	const GITHUB_OWNER   = 'thesavemart0-ship-it';
	const GITHUB_REPO    = 'bizgrowth360-android-builder';
	const GITHUB_BRANCH  = 'main';
	const WORKFLOW_FILE  = 'android-build.yml';
	const API_BASE       = 'https://api.github.com';
	const TOKEN_CONSTANT = 'BIZGROWTH360_GITHUB_TOKEN';

	/**
	 * Get the GitHub repository owner.
	 *
	 * @return string
	 */
	public static function get_github_owner() {
		return self::GITHUB_OWNER;
	}

	/**
	 * Get the GitHub repository name.
	 *
	 * @return string
	 */
	public static function get_github_repo() {
		return self::GITHUB_REPO;
	}

	/**
	 * Get the Git branch for workflow dispatch.
	 *
	 * @return string
	 */
	public static function get_github_branch() {
		return self::GITHUB_BRANCH;
	}

	/**
	 * Get the workflow file name.
	 *
	 * @return string
	 */
	public static function get_workflow_file() {
		return self::WORKFLOW_FILE;
	}

	/**
	 * Get the GitHub API base URL.
	 *
	 * @return string
	 */
	public static function get_api_base() {
		return self::API_BASE;
	}

	/**
	 * Retrieve token securely from wp-config.php constant (or environment).
	 *
	 * Never exposes the token or stores it in database options.
	 *
	 * @return string
	 */
	public static function get_token() {
		if ( defined( self::TOKEN_CONSTANT ) && ! empty( constant( self::TOKEN_CONSTANT ) ) ) {
			return trim( constant( self::TOKEN_CONSTANT ) );
		}

		// Environment fallback for CLI and test runners
		$env_token = getenv( self::TOKEN_CONSTANT );
		if ( ! empty( $env_token ) ) {
			return trim( $env_token );
		}

		return '';
	}

	/**
	 * Check whether the build engine token is defined.
	 *
	 * @return bool
	 */
	public static function has_token() {
		$token = self::get_token();
		return ! empty( $token );
	}
}
