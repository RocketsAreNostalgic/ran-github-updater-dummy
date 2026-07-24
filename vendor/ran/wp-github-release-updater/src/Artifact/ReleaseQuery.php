<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Bounded release-discovery request.
 */
final class ReleaseQuery {
	public const STABLE     = 'stable';
	public const PRERELEASE = 'prerelease';

	public function __construct(
		private Repository $repository,
		private AssetNaming $assetNaming,
		private string $pluginRoot,
		private string $mainFile,
		private string $channel = self::STABLE,
		private string $phpVersion = PHP_VERSION,
		private string $wordpressVersion = '6.5',
		private int $candidateLimit = 5,
		private ?ConditionalState $conditional = null
	) {
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function assetNaming(): AssetNaming {
		return $this->assetNaming;
	}

	public function pluginRoot(): string {
		return $this->pluginRoot;
	}

	public function mainFile(): string {
		return $this->mainFile;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function phpVersion(): string {
		return $this->phpVersion;
	}

	public function wordpressVersion(): string {
		return $this->wordpressVersion;
	}

	public function candidateLimit(): int {
		return max( 1, min( 10, $this->candidateLimit ) );
	}

	public function conditional(): ConditionalState {
		return $this->conditional ?? new ConditionalState();
	}

	/**
	 * @return true|\WP_Error
	 */
	public function validate() {
		if ( self::STABLE !== $this->channel && self::PRERELEASE !== $this->channel ) {
			return new \WP_Error( 'github_updater_invalid_channel', 'The release channel is invalid.' );
		}
		if ( 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $this->pluginRoot ) ) {
			return new \WP_Error( 'github_updater_invalid_plugin_root', 'The canonical plugin root is invalid.' );
		}
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.php\z/D', $this->mainFile ) ) {
			return new \WP_Error( 'github_updater_invalid_main_file', 'The canonical main plugin filename is invalid.' );
		}

		return true;
	}
}
