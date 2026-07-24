<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Validated RAN WordPress plugin release manifest.
 */
final class Manifest {
	public const SCHEMA         = 'ran-wordpress-plugin-release';
	public const SCHEMA_VERSION = 1;

	public function __construct(
		private string $repository,
		private string $tag,
		private string $commit,
		private string $zip,
		private string $pluginRoot,
		private string $mainFile,
		private string $version,
		private string $requiresPhp,
		private string $requiresWordPress,
		private string $testedWordPress,
		private int $zipSize,
		private string $zipSha256
	) {
	}

	public function repository(): string {
		return $this->repository;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function commit(): string {
		return $this->commit;
	}

	public function zip(): string {
		return $this->zip;
	}

	public function pluginRoot(): string {
		return $this->pluginRoot;
	}

	public function mainFile(): string {
		return $this->mainFile;
	}

	public function version(): string {
		return $this->version;
	}

	public function requiresPhp(): string {
		return $this->requiresPhp;
	}

	public function requiresWordPress(): string {
		return $this->requiresWordPress;
	}

	public function testedWordPress(): string {
		return $this->testedWordPress;
	}

	public function zipSize(): int {
		return $this->zipSize;
	}

	public function zipSha256(): string {
		return $this->zipSha256;
	}
}
