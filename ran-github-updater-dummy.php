<?php
/**
 * Plugin Name: RAN GitHub Updater Dummy
 * Description: Disposable public fixture for the RAN GitHub Release Updater.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Update URI: https://github.com/RocketsAreNostalgic/ran-github-updater-dummy
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$create_updater = require __DIR__
	. '/vendor/ran/wp-github-release-updater/bootstrap.php';

$updater = $create_updater(
	pluginFile: __FILE__,
	repository: 'RocketsAreNostalgic/ran-github-updater-dummy',
	pluginSlug: 'ran-github-updater-dummy',
	channel: 'stable',
	accessToken: null,
	assetPrefix: 'ran-github-updater-dummy',
	manifestPublicKey: null,
	autoUpdatePolicy: 'site-controlled',
	cacheDuration: 300,
	failureCacheDuration: 60,
);

$updater->register();
