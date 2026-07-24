<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Bounded published-release projection.
 */
final class ReleaseSummary {
	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version,
		private bool $prerelease,
		private string $publishedAt
	) {
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}

	public function isPrerelease(): bool {
		return $this->prerelease;
	}

	public function publishedAt(): string {
		return $this->publishedAt;
	}
}
