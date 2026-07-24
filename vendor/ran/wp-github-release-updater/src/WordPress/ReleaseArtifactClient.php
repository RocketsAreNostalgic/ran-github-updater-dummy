<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;

/**
 * Small adapter-facing view of the hook-free artifact service.
 *
 * @internal
 */
interface ReleaseArtifactClient {

	/**
	 * @return \RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseListResult|\WP_Error
	 */
	public function listReleases( ReleaseQuery $query );

	/**
	 * @return ArtifactDescriptor|\WP_Error
	 */
	public function describeExact( ExactReleaseRequest $request );

	/**
	 * @return \RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact|\WP_Error
	 */
	public function acquire( ArtifactDescriptor $descriptor );
}
