<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;

/**
 * One-time claimable verified local artifact.
 */
final class VerifiedArtifact {
	private bool $claimed = false;

	private bool $discarded = false;

	/**
	 * @param array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int} $identity Frozen file identity.
	 */
	public function __construct(
		private string $path,
		private int $size,
		private string $sha256,
		private ArtifactDescriptor $descriptor,
		private TemporaryFileFactory $temporaryFiles,
		private array $identity
	) {
	}

	public function __destruct() {
		if ( ! $this->claimed && ! $this->discarded ) {
			$this->temporaryFiles->delete( $this->path );
			$this->discarded = true;
		}
	}

	public function size(): int {
		return $this->size;
	}

	public function sha256(): string {
		return $this->sha256;
	}

	public function descriptor(): ArtifactDescriptor {
		return $this->descriptor;
	}

	/**
	 * Transfer permanent cleanup ownership to the caller.
	 *
	 * @return ClaimedArtifact|\WP_Error
	 */
	public function claim() {
		if ( $this->claimed || $this->discarded ) {
			return new \WP_Error(
				'github_updater_artifact_already_claimed',
				'The verified artifact can be claimed only once.'
			);
		}

		$identity = self::fileIdentity( $this->path );
		$sha256   = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;
		if ( null === $identity || $identity !== $this->identity || $sha256 !== $this->sha256 ) {
			$this->temporaryFiles->delete( $this->path );
			$this->discarded = true;

			return new \WP_Error(
				'github_updater_artifact_identity_changed',
				'The verified artifact changed before custody transfer.'
			);
		}

		$this->claimed = true;

		return new ClaimedArtifact( $this->path, $this->size, $this->sha256, $this->descriptor );
	}

	public function discard(): void {
		if ( ! $this->claimed && ! $this->discarded ) {
			$this->temporaryFiles->delete( $this->path );
			$this->discarded = true;
		}
	}

	/**
	 * @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int}|null
	 */
	public static function fileIdentity( string $path ): ?array {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $stat
			|| ! is_file( $path )
			|| 0100000 !== ( (int) $stat['mode'] & 0170000 )
		) {
			return null;
		}

		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}
}
