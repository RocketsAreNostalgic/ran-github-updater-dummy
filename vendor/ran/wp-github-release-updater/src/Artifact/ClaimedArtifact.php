<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Local artifact whose cleanup ownership has transferred to the caller.
 */
final class ClaimedArtifact {
	public function __construct(
		private string $path,
		private int $size,
		private string $sha256,
		private ArtifactDescriptor $descriptor
	) {
	}

	public function path(): string {
		return $this->path;
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
}
