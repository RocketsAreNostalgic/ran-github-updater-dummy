<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Endpoint-scoped HTTP conditional validators.
 */
final class ConditionalState {
	public function __construct(
		private ?string $etag = null,
		private ?string $lastModified = null
	) {
	}

	public function etag(): ?string {
		return $this->etag;
	}

	public function lastModified(): ?string {
		return $this->lastModified;
	}

	/**
	 * @return array<string, string>
	 */
	public function requestHeaders(): array {
		$headers = array();
		if ( null !== $this->etag && '' !== $this->etag ) {
			$headers['If-None-Match'] = $this->etag;
		}
		if ( null !== $this->lastModified && '' !== $this->lastModified ) {
			$headers['If-Modified-Since'] = $this->lastModified;
		}

		return $headers;
	}

	public function isEmpty(): bool {
		return null === $this->etag && null === $this->lastModified;
	}
}
