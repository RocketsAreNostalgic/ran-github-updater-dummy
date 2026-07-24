<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Immutable path-free verified release description.
 */
final class ArtifactDescriptor {
	public function __construct(
		private ReleaseQuery $query,
		private Repository $repository,
		private int $releaseId,
		private string $tag,
		private string $version,
		private string $commit,
		private bool $prerelease,
		private string $detailsUrl,
		private ReleaseAsset $zipAsset,
		private ReleaseAsset $manifestAsset,
		private Manifest $manifest
	) {
	}

	public function query(): ReleaseQuery {
		return $this->query;
	}

	public function repository(): Repository {
		return $this->repository;
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

	public function commit(): string {
		return $this->commit;
	}

	public function isPrerelease(): bool {
		return $this->prerelease;
	}

	public function detailsUrl(): string {
		return $this->detailsUrl;
	}

	public function zipAsset(): ReleaseAsset {
		return $this->zipAsset;
	}

	public function manifestAsset(): ReleaseAsset {
		return $this->manifestAsset;
	}

	public function manifest(): Manifest {
		return $this->manifest;
	}

	public function equals( self $other ): bool {
		return $this->repository->equals( $other->repository )
			&& $this->releaseId === $other->releaseId
			&& $this->tag === $other->tag
			&& $this->version === $other->version
			&& $this->commit === $other->commit
			&& $this->prerelease === $other->prerelease
			&& $this->detailsUrl === $other->detailsUrl
			&& $this->zipAsset->equals( $other->zipAsset )
			&& $this->manifestAsset->equals( $other->manifestAsset )
			&& $this->manifest->repository() === $other->manifest->repository()
			&& $this->manifest->tag() === $other->manifest->tag()
			&& $this->manifest->commit() === $other->manifest->commit()
			&& $this->manifest->zip() === $other->manifest->zip()
			&& $this->manifest->zipSha256() === $other->manifest->zipSha256()
			&& $this->manifest->pluginRoot() === $other->manifest->pluginRoot()
			&& $this->manifest->mainFile() === $other->manifest->mainFile()
			&& $this->manifest->version() === $other->manifest->version()
			&& $this->manifest->requiresPhp() === $other->manifest->requiresPhp()
			&& $this->manifest->requiresWordPress() === $other->manifest->requiresWordPress()
			&& $this->manifest->testedWordPress() === $other->manifest->testedWordPress()
			&& $this->manifest->zipSize() === $other->manifest->zipSize();
	}
}
