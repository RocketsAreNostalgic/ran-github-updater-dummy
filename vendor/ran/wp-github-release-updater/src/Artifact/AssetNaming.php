<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Exact GitHub Release asset naming.
 */
final class AssetNaming {
	private function __construct( private string $prefix ) {
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function fromPrefix( string $prefix ) {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $prefix ) ) {
			return new \WP_Error(
				'github_updater_invalid_asset_prefix',
				'The release asset prefix is invalid.'
			);
		}

		return new self( $prefix );
	}

	public function prefix(): string {
		return $this->prefix;
	}

	public function zip( string $version ): string {
		return $this->prefix . '-' . $version . '.zip';
	}

	public function manifest( string $version ): string {
		return $this->prefix . '-' . $version . '.json';
	}
}
