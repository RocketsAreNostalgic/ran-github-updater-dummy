<?php
/**
 * Selected Alpha 1 runtime entrypoint.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

$ran_wp_github_release_updater_sources = array(
	'/src/Http/Transport.php',
	'/src/Http/TemporaryFileFactory.php',
	'/src/Http/Request.php',
	'/src/Http/Response.php',
	'/src/Http/WordPressSafeHttpTransport.php',
	'/src/Http/WordPressTemporaryFileFactory.php',
	'/src/Artifact/Repository.php',
	'/src/Artifact/AssetNaming.php',
	'/src/Artifact/ConditionalState.php',
	'/src/Artifact/RateLimit.php',
	'/src/Artifact/ReleaseAsset.php',
	'/src/Artifact/Manifest.php',
	'/src/Artifact/ReleaseSummary.php',
	'/src/Artifact/ReleaseListResult.php',
	'/src/Artifact/ReleaseQuery.php',
	'/src/Artifact/ExactReleaseRequest.php',
	'/src/Artifact/ArtifactDescriptor.php',
	'/src/Artifact/ClaimedArtifact.php',
	'/src/Artifact/VerifiedArtifact.php',
	'/src/Artifact/GitHubReleaseArtifactService.php',
	'/src/WordPress/ReleaseArtifactClient.php',
	'/src/WordPress/GitHubReleaseArtifactClient.php',
	'/src/WordPress/NativePluginUpdater.php',
);

foreach ( $ran_wp_github_release_updater_sources as $ran_wp_github_release_updater_source ) {
	require_once __DIR__ . $ran_wp_github_release_updater_source;
}

unset(
	$ran_wp_github_release_updater_source,
	$ran_wp_github_release_updater_sources
);

use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressSafeHttpTransport;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\GitHubReleaseArtifactClient;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\NativePluginUpdater;

return static function ( array $targets ): void {
	$broker = $GLOBALS['ran_wp_github_release_updater_v1_broker'] ?? null;
	if (
		! is_object( $broker )
		|| ! is_callable( array( $broker, 'attachDiagnosticsProvider' ) )
	) {
		return;
	}

	$artifact_client = new GitHubReleaseArtifactClient(
		new GitHubReleaseArtifactService( new WordPressSafeHttpTransport() )
	);

	$targets_by_basename = array();
	foreach ( $targets as $target ) {
		if ( ! is_array( $target ) ) {
			continue;
		}

		$registration_id = $target['registrationId'] ?? null;
		$plugin_file     = $target['pluginFile'] ?? null;
		if ( ! is_string( $registration_id ) || '' === $registration_id ) {
			continue;
		}
		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			$targets_by_basename[ 'invalid:' . $registration_id ][] = $target;
			continue;
		}

		$basename                           = strtolower(
			str_replace( '\\', '/', plugin_basename( $plugin_file ) )
		);
		$targets_by_basename[ $basename ][] = $target;
	}

	foreach ( $targets_by_basename as $group ) {
		if ( count( $group ) > 1 ) {
			foreach ( $group as $target ) {
				$registration_id = $target['registrationId'];
				$broker->attachDiagnosticsProvider(
					$registration_id,
					static fn (): array => array(
						'state' => 'inactive',
						'code'  => 'conflicting_plugin_target',
					)
				);
			}
			continue;
		}

		$target          = $group[0];
		$registration_id = $target['registrationId'];

		try {
			$updater = NativePluginUpdater::fromTarget( $target, $artifact_client );
			if ( $updater instanceof \WP_Error ) {
				$code = sanitize_key( $updater->get_error_code() );
				$code = '' === $code ? 'invalid_target_configuration' : substr( $code, 0, 80 );
				$broker->attachDiagnosticsProvider(
					$registration_id,
					static fn (): array => array(
						'state' => 'inactive',
						'code'  => $code,
					)
				);
				continue;
			}

			$updater->register();
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => $updater->diagnostics()
			);
		} catch ( Throwable ) {
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => array(
					'state' => 'inactive',
					'code'  => 'runtime_target_failed',
				)
			);
		}
	}
};
