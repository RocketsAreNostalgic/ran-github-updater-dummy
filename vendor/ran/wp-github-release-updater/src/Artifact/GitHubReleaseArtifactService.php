<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;
use RAN\WPGitHubReleaseUpdater\V1\Http\Transport;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressTemporaryFileFactory;

/**
 * Hook-free and persistence-free public GitHub release-artifact service.
 */
final class GitHubReleaseArtifactService {
	private const API_ORIGIN              = 'https://api.github.com';
	private const RELEASE_PAGE_SIZE       = 20;
	private const RELEASE_RESPONSE_LIMIT  = 262144;
	private const MANIFEST_RESPONSE_LIMIT = 16384;
	private const PACKAGE_SIZE_LIMIT      = 52428800;
	private const HTTP_TIMEOUT            = 15;

	/**
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	public function __construct(
		private Transport $transport,
		?TemporaryFileFactory $temporaryFiles = null,
		?callable $clock = null
	) {
		$this->temporaryFiles = $temporaryFiles ?? new WordPressTemporaryFileFactory();
		$this->clock          = null === $clock ? static fn (): int => time() : \Closure::fromCallable( $clock );
	}

	private TemporaryFileFactory $temporaryFiles;

	/**
	 * List a bounded page of eligible stable or prerelease releases.
	 *
	 * @return ReleaseListResult|\WP_Error
	 */
	public function listReleases( ReleaseQuery $query ) {
		$valid = $query->validate();
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}

		$url      = $this->repositoryApiUrl( $query->repository() )
			. '/releases?per_page=' . self::RELEASE_PAGE_SIZE . '&page=1';
		$response = $this->transport->get(
			new Request(
				$url,
				array_merge( $this->jsonHeaders(), $query->conditional()->requestHeaders() ),
				self::HTTP_TIMEOUT,
				self::RELEASE_RESPONSE_LIMIT
			)
		);
		if ( $response instanceof \WP_Error ) {
			return $this->transportError( $response );
		}

		$conditional = $this->conditionalFromResponse( $response );
		$rateLimit   = $this->rateLimitFromResponse( $response, 900 );
		if ( 304 === $response->statusCode() ) {
			return new ReleaseListResult( array(), $conditional, $rateLimit, true );
		}
		if ( $rateLimit->isLimited() ) {
			return new ReleaseListResult( array(), $conditional, $rateLimit );
		}
		if ( 200 !== $response->statusCode() ) {
			return $this->httpError( $response->statusCode() );
		}

		$decoded = $this->decodeObjectList( $response->body() );
		if ( $decoded instanceof \WP_Error ) {
			return $decoded;
		}

		$releases = array();
		foreach ( $decoded as $candidate ) {
			$summary = $this->summaryFromRelease( $candidate, $query );
			if ( null !== $summary ) {
				$releases[] = $summary;
			}
		}

		usort(
			$releases,
			static fn ( ReleaseSummary $left, ReleaseSummary $right ): int =>
				version_compare( $right->version(), $left->version() )
		);

		return new ReleaseListResult(
			array_slice( $releases, 0, $query->candidateLimit() ),
			$conditional,
			$rateLimit
		);
	}

	/**
	 * Resolve the newest eligible release into a verified path-free descriptor.
	 *
	 * @return ArtifactDescriptor|\WP_Error
	 */
	public function describeLatest( ReleaseQuery $query ) {
		$list = $this->listReleases( $query );
		if ( $list instanceof \WP_Error ) {
			return $list;
		}
		if ( $list->isNotModified() ) {
			return new \WP_Error(
				'github_updater_not_modified_without_cached_release',
				'GitHub returned not modified; the caller must reuse its validated release state.'
			);
		}
		if ( $list->rateLimit()->isLimited() ) {
			return new \WP_Error(
				'github_updater_rate_limited',
				'GitHub release discovery is temporarily rate limited.',
				array( 'cooldown' => $list->rateLimit()->cooldownSeconds() )
			);
		}

		foreach ( $list->releases() as $release ) {
			$descriptor = $this->describeExact(
				new ExactReleaseRequest( $query, $release->releaseId(), $release->tag() )
			);
			if ( ! $descriptor instanceof \WP_Error ) {
				return $descriptor;
			}
			$error_code = $descriptor->get_error_code();
			if ( 'github_updater_release_incompatible' !== $error_code ) {
				/*
				 * Once GitHub advertises a syntactically eligible newest
				 * release, a broken trust contract is not permission to
				 * silently downgrade to an older artifact.
				 */
				return $descriptor;
			}
		}

		return new \WP_Error(
			'github_updater_no_eligible_release',
			'No eligible GitHub Release satisfied the artifact contract.'
		);
	}

	/**
	 * Resolve one exact release ID and validate its complete artifact contract.
	 *
	 * @return ArtifactDescriptor|\WP_Error
	 */
	public function describeExact( ExactReleaseRequest $request ) {
		$query = $request->query();
		$valid = $query->validate();
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		if ( $request->releaseId() < 1 ) {
			return new \WP_Error( 'github_updater_invalid_release_id', 'The exact release ID is invalid.' );
		}

		$response = $this->getJson(
			$this->repositoryApiUrl( $query->repository() ) . '/releases/' . $request->releaseId(),
			self::RELEASE_RESPONSE_LIMIT
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$release = $this->decodeObject( $response->body() );
		if ( $release instanceof \WP_Error ) {
			return $release;
		}

		return $this->descriptorFromRelease( $query, $request, $release );
	}

	/**
	 * Re-resolve an exact offer and fail if any trusted identity changed.
	 *
	 * @return ArtifactDescriptor|\WP_Error
	 */
	public function revalidate( ArtifactDescriptor $descriptor ) {
		$current = $this->describeExact(
			new ExactReleaseRequest(
				$descriptor->query(),
				$descriptor->releaseId(),
				$descriptor->tag()
			)
		);
		if ( $current instanceof \WP_Error ) {
			return $current;
		}
		if ( ! $descriptor->equals( $current ) ) {
			return new \WP_Error(
				'github_updater_artifact_continuity_failed',
				'The exact GitHub Release or artifact identity changed after discovery.'
			);
		}

		return $current;
	}

	/**
	 * Revalidate, download, and verify an exact release asset.
	 *
	 * @return VerifiedArtifact|\WP_Error
	 */
	public function acquire( ArtifactDescriptor $descriptor ) {
		$current = $this->revalidate( $descriptor );
		if ( $current instanceof \WP_Error ) {
			return $current;
		}

		$path = $this->temporaryFiles->create( $descriptor->zipAsset()->name() );
		if ( $path instanceof \WP_Error ) {
			return $path;
		}

		$response = $this->transport->get(
			new Request(
				$this->assetApiUrl( $descriptor->repository(), $descriptor->zipAsset()->id() ),
				$this->binaryHeaders(),
				self::HTTP_TIMEOUT,
				self::PACKAGE_SIZE_LIMIT + 1,
				$path
			)
		);
		if ( $response instanceof \WP_Error ) {
			$this->temporaryFiles->delete( $path );
			return $this->transportError( $response );
		}
		if ( 200 !== $response->statusCode() ) {
			$this->temporaryFiles->delete( $path );
			return $this->httpError( $response->statusCode() );
		}

		$identity = VerifiedArtifact::fileIdentity( $path );
		if ( null === $identity
			|| 1 !== $identity['nlink']
			|| 0600 !== ( $identity['mode'] & 0777 )
			|| $identity['size'] !== $descriptor->manifest()->zipSize()
			|| $identity['size'] > self::PACKAGE_SIZE_LIMIT
		) {
			$this->temporaryFiles->delete( $path );
			return new \WP_Error(
				'github_updater_downloaded_artifact_invalid',
				'The downloaded release asset did not match its declared file identity or size.'
			);
		}

		$sha256 = hash_file( 'sha256', $path );
		if ( false === $sha256 || ! hash_equals( $descriptor->manifest()->zipSha256(), $sha256 ) ) {
			$this->temporaryFiles->delete( $path );
			return new \WP_Error(
				'github_updater_downloaded_digest_mismatch',
				'The downloaded release asset did not match its expected SHA-256 digest.'
			);
		}

		return new VerifiedArtifact(
			$path,
			$identity['size'],
			$sha256,
			$descriptor,
			$this->temporaryFiles,
			$identity
		);
	}

	/**
	 * @param array<string, mixed> $release Release API response.
	 * @return ArtifactDescriptor|\WP_Error
	 */
	private function descriptorFromRelease(
		ReleaseQuery $query,
		ExactReleaseRequest $request,
		array $release
	) {
		$id = $this->positiveInt( $release['id'] ?? null );
		if ( null === $id || $request->releaseId() !== $id ) {
			return $this->continuityError( 'release ID' );
		}
		if ( true === ( $release['draft'] ?? null ) ) {
			return new \WP_Error( 'github_updater_release_is_draft', 'The selected GitHub Release is a draft.' );
		}

		$tag     = is_string( $release['tag_name'] ?? null ) ? $release['tag_name'] : '';
		$version = self::semanticVersion( $tag );
		if ( null === $version ) {
			return new \WP_Error( 'github_updater_invalid_release_tag', 'The selected release tag is not semantic.' );
		}
		if ( null !== $request->expectedTag() && $request->expectedTag() !== $tag ) {
			return $this->continuityError( 'release tag' );
		}

		$prerelease = true === ( $release['prerelease'] ?? null );
		if ( ReleaseQuery::STABLE === $query->channel()
			&& ( $prerelease || str_contains( $version, '-' ) )
		) {
			return new \WP_Error(
				'github_updater_prerelease_not_allowed',
				'A prerelease cannot satisfy a stable release channel.'
			);
		}

		$detailsUrl = is_string( $release['html_url'] ?? null ) ? $release['html_url'] : '';
		$prefix     = 'https://github.com/' . $query->repository()->canonical() . '/releases/';
		if ( ! str_starts_with( $detailsUrl, $prefix ) ) {
			return new \WP_Error( 'github_updater_invalid_release_url', 'The GitHub Release URL is invalid.' );
		}

		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$zip    = $this->exactAsset( $assets, $query->assetNaming()->zip( $version ), true );
		if ( $zip instanceof \WP_Error ) {
			return $zip;
		}
		$manifestAsset = $this->exactAsset(
			$assets,
			$query->assetNaming()->manifest( $version ),
			false
		);
		if ( $manifestAsset instanceof \WP_Error ) {
			return $manifestAsset;
		}

		$manifestResponse = $this->getJson(
			$this->assetApiUrl( $query->repository(), $manifestAsset->id() ),
			self::MANIFEST_RESPONSE_LIMIT,
			true
		);
		if ( $manifestResponse instanceof \WP_Error ) {
			return $manifestResponse;
		}
		$manifestData = $this->decodeObject( $manifestResponse->body() );
		if ( $manifestData instanceof \WP_Error ) {
			return new \WP_Error( 'github_updater_invalid_manifest_json', 'The release manifest is not valid JSON.' );
		}

		$commit = $this->resolveCommit( $query->repository(), $tag );
		if ( $commit instanceof \WP_Error ) {
			return $commit;
		}

		$manifest = $this->validateManifest(
			$manifestData,
			$query,
			$tag,
			$version,
			$commit,
			$zip
		);
		if ( $manifest instanceof \WP_Error ) {
			return $manifest;
		}

		return new ArtifactDescriptor(
			$query,
			$query->repository(),
			$id,
			$tag,
			$version,
			$commit,
			$prerelease,
			$detailsUrl,
			$zip,
			$manifestAsset,
			$manifest
		);
	}

	/**
	 * @param array<int, mixed> $assets Release assets.
	 * @return ReleaseAsset|\WP_Error
	 */
	private function exactAsset( array $assets, string $expectedName, bool $requireDigest ) {
		$matches = array();
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && ( $asset['name'] ?? null ) === $expectedName ) {
				$matches[] = $asset;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return new \WP_Error(
				'github_updater_ambiguous_release_asset',
				'The release must contain exactly one asset with each expected name.'
			);
		}

		$asset = $matches[0];
		$id    = $this->positiveInt( $asset['id'] ?? null );
		$size  = $this->positiveInt( $asset['size'] ?? null );
		if ( null === $id || null === $size || 'uploaded' !== ( $asset['state'] ?? null ) ) {
			return new \WP_Error(
				'github_updater_invalid_release_asset',
				'The expected release asset is not completely uploaded.'
			);
		}
		if ( $requireDigest && $size > self::PACKAGE_SIZE_LIMIT ) {
			return new \WP_Error(
				'github_updater_release_asset_too_large',
				'The release asset exceeds the package size limit.'
			);
		}

		$sha256 = null;
		if ( $requireDigest ) {
			$digest = is_string( $asset['digest'] ?? null ) ? strtolower( $asset['digest'] ) : '';
			if ( 1 !== preg_match( '/\Asha256:([a-f0-9]{64})\z/D', $digest, $matches ) ) {
				return new \WP_Error(
					'github_updater_missing_asset_digest',
					'The GitHub release asset does not provide a supported SHA-256 digest.'
				);
			}
			$sha256 = $matches[1];
		}

		return new ReleaseAsset( $id, $expectedName, $size, $sha256 );
	}

	/**
	 * @param array<string, mixed> $data Manifest data.
	 * @return Manifest|\WP_Error
	 */
	private function validateManifest(
		array $data,
		ReleaseQuery $query,
		string $tag,
		string $version,
		string $commit,
		ReleaseAsset $zip
	) {
		$requiredStrings = array(
			'schema',
			'repository',
			'tag',
			'commit',
			'zip',
			'plugin_root',
			'main_file',
			'version',
			'requires_php',
			'requires_wordpress',
			'tested_wordpress',
			'zip_sha256',
		);
		foreach ( $requiredStrings as $key ) {
			if ( ! is_string( $data[ $key ] ?? null ) || '' === $data[ $key ] ) {
				return new \WP_Error(
					'github_updater_invalid_manifest',
					'The release manifest is missing a required value.'
				);
			}
		}
		if ( Manifest::SCHEMA !== $data['schema'] || Manifest::SCHEMA_VERSION !== ( $data['schema_version'] ?? null ) ) {
			return new \WP_Error( 'github_updater_manifest_schema_unsupported', 'The release manifest schema is unsupported.' );
		}

		$zipSize = $this->positiveInt( $data['zip_size'] ?? null );
		if ( null === $zipSize ) {
			return new \WP_Error( 'github_updater_invalid_manifest_size', 'The manifest ZIP size is invalid.' );
		}

		$sha256 = strtolower( $data['zip_sha256'] );
		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 ) ) {
			return new \WP_Error( 'github_updater_invalid_manifest_digest', 'The manifest ZIP digest is invalid.' );
		}

		$identityMatches = 0 === strcasecmp( $query->repository()->canonical(), $data['repository'] )
			&& $data['tag'] === $tag
			&& strtolower( $data['commit'] ) === $commit
			&& $data['zip'] === $zip->name()
			&& $data['plugin_root'] === $query->pluginRoot()
			&& $data['main_file'] === $query->mainFile()
			&& $data['version'] === $version;
		if ( ! $identityMatches ) {
			return new \WP_Error(
				'github_updater_manifest_identity_mismatch',
				'The release manifest does not match the selected repository, release, commit, or plugin.'
			);
		}

		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', strtolower( $data['commit'] ) )
			|| 1 !== preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/D', $data['requires_php'] )
			|| 1 !== preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/D', $data['requires_wordpress'] )
			|| 1 !== preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/D', $data['tested_wordpress'] )
		) {
			return new \WP_Error(
				'github_updater_invalid_manifest_compatibility',
				'The release manifest contains invalid commit or compatibility values.'
			);
		}
		if ( version_compare( $query->phpVersion(), $data['requires_php'], '<' )
			|| version_compare( $query->wordpressVersion(), $data['requires_wordpress'], '<' )
		) {
			return new \WP_Error(
				'github_updater_release_incompatible',
				'The release is not compatible with this PHP or WordPress version.'
			);
		}

		if ( $zipSize !== $zip->size()
			|| $zipSize > self::PACKAGE_SIZE_LIMIT
			|| null === $zip->sha256()
			|| ! hash_equals( $zip->sha256(), $sha256 )
		) {
			return new \WP_Error(
				'github_updater_manifest_artifact_mismatch',
				'The manifest, GitHub asset size, and GitHub asset digest do not agree.'
			);
		}

		return new Manifest(
			$query->repository()->canonical(),
			$tag,
			$commit,
			$zip->name(),
			$query->pluginRoot(),
			$query->mainFile(),
			$version,
			$data['requires_php'],
			$data['requires_wordpress'],
			$data['tested_wordpress'],
			$zipSize,
			$sha256
		);
	}

	/**
	 * @param array<string, mixed> $release Release API response.
	 */
	private function summaryFromRelease( array $release, ReleaseQuery $query ): ?ReleaseSummary {
		if ( true === ( $release['draft'] ?? null ) ) {
			return null;
		}
		$id          = $this->positiveInt( $release['id'] ?? null );
		$tag         = is_string( $release['tag_name'] ?? null ) ? $release['tag_name'] : '';
		$version     = self::semanticVersion( $tag );
		$prerelease  = true === ( $release['prerelease'] ?? null );
		$publishedAt = is_string( $release['published_at'] ?? null ) ? $release['published_at'] : '';
		if ( null === $id || null === $version || '' === $publishedAt ) {
			return null;
		}
		if ( ReleaseQuery::STABLE === $query->channel()
			&& ( $prerelease || str_contains( $version, '-' ) )
		) {
			return null;
		}

		$assets        = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$expectedNames = array(
			$query->assetNaming()->zip( $version )      => 0,
			$query->assetNaming()->manifest( $version ) => 0,
		);
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || 'uploaded' !== ( $asset['state'] ?? null ) ) {
				continue;
			}
			$name = $asset['name'] ?? null;
			if ( is_string( $name ) && array_key_exists( $name, $expectedNames ) ) {
				++$expectedNames[ $name ];
			}
		}
		if ( array( 1, 1 ) !== array_values( $expectedNames ) ) {
			return null;
		}

		return new ReleaseSummary( $id, $tag, $version, $prerelease, $publishedAt );
	}

	/**
	 * Resolve both lightweight and annotated release tags through GitHub's
	 * commit endpoint.
	 *
	 * @return string|\WP_Error
	 */
	private function resolveCommit( Repository $repository, string $tag ) {
		$response = $this->getJson(
			$this->repositoryApiUrl( $repository ) . '/commits/' . rawurlencode( $tag ),
			self::MANIFEST_RESPONSE_LIMIT
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		$data = $this->decodeObject( $response->body() );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$commit = is_string( $data['sha'] ?? null ) ? strtolower( $data['sha'] ) : '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $commit ) ) {
			return new \WP_Error(
				'github_updater_invalid_tag_commit',
				'GitHub did not resolve the release tag to a full commit SHA.'
			);
		}

		return $commit;
	}

	/**
	 * @return Response|\WP_Error
	 */
	private function getJson( string $url, int $limit, bool $binary = false ) {
		$response = $this->transport->get(
			new Request(
				$url,
				$binary ? $this->binaryHeaders() : $this->jsonHeaders(),
				self::HTTP_TIMEOUT,
				$limit
			)
		);
		if ( $response instanceof \WP_Error ) {
			return $this->transportError( $response );
		}

		$rateLimit = $this->rateLimitFromResponse( $response, 900 );
		if ( $rateLimit->isLimited() ) {
			return new \WP_Error(
				'github_updater_rate_limited',
				'GitHub temporarily rate limited the release request.',
				array( 'cooldown' => $rateLimit->cooldownSeconds() )
			);
		}
		if ( 200 !== $response->statusCode() ) {
			return $this->httpError( $response->statusCode() );
		}

		return $response;
	}

	private function repositoryApiUrl( Repository $repository ): string {
		return self::API_ORIGIN . '/repos/' . $repository->apiPath();
	}

	private function assetApiUrl( Repository $repository, int $assetId ): string {
		return $this->repositoryApiUrl( $repository ) . '/releases/assets/' . $assetId;
	}

	/**
	 * @return array<string, string>
	 */
	private function jsonHeaders(): array {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'ran-wp-github-release-updater/1.0.0-alpha.1',
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function binaryHeaders(): array {
		$headers           = $this->jsonHeaders();
		$headers['Accept'] = 'application/octet-stream';

		return $headers;
	}

	private function conditionalFromResponse( Response $response ): ConditionalState {
		$etag         = $this->boundedHeader( $response->header( 'etag' ), 512 );
		$lastModified = $this->boundedHeader( $response->header( 'last-modified' ), 128 );

		return new ConditionalState( $etag, $lastModified );
	}

	private function rateLimitFromResponse( Response $response, int $fallback ): RateLimit {
		$remaining = $this->nonNegativeInt( $response->header( 'x-ratelimit-remaining' ) );
		$resetAt   = $this->nonNegativeInt( $response->header( 'x-ratelimit-reset' ) );
		$retry     = $this->positiveInt( $response->header( 'retry-after' ) );
		$limited   = 429 === $response->statusCode()
			|| ( 403 === $response->statusCode() && ( null !== $retry || 0 === $remaining ) );

		if ( ! $limited ) {
			return new RateLimit( RateLimit::NONE, $remaining, $resetAt );
		}

		$now      = ( $this->clock )();
		$cooldown = $fallback;
		if ( null !== $retry ) {
			$cooldown = $retry;
		} elseif ( null !== $resetAt && $resetAt > $now ) {
			$cooldown = $resetAt - $now;
		}
		$cooldown = max( 1, min( 86400, $cooldown ) );

		return new RateLimit( RateLimit::LIMITED, $remaining, $resetAt, $cooldown );
	}

	/**
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private function decodeObjectList( string $body ) {
		$decoded = json_decode( $body, true, 32 );
		if ( ! is_array( $decoded ) || ! self::isList( $decoded ) ) {
			return new \WP_Error( 'github_updater_invalid_json', 'GitHub returned an invalid releases response.' );
		}

		$objects = array();
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) ) {
				$objects[] = $item;
			}
		}

		return $objects;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function decodeObject( string $body ) {
		$decoded = json_decode( $body, true, 32 );
		if ( ! is_array( $decoded ) || self::isList( $decoded ) ) {
			return new \WP_Error( 'github_updater_invalid_json', 'GitHub returned an invalid response.' );
		}

		return $decoded;
	}

	/**
	 * @return int|null
	 */
	private function positiveInt( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A[1-9]\d*\z/D', $value ) ) {
			$integer = filter_var( $value, FILTER_VALIDATE_INT );
			return false === $integer ? null : $integer;
		}

		return null;
	}

	private function nonNegativeInt( ?string $value ): ?int {
		if ( null === $value || 1 !== preg_match( '/\A\d+\z/D', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );

		return false === $integer ? null : $integer;
	}

	private function boundedHeader( ?string $value, int $limit ): ?string {
		if ( null === $value || '' === $value || strlen( $value ) > $limit || str_contains( $value, "\n" ) ) {
			return null;
		}

		return $value;
	}

	private static function semanticVersion( string $tag ): ?string {
		if ( 1 !== preg_match(
			'/\Av?((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
				. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
				. '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?)\z/D',
			$tag,
			$matches
		) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * PHP 8.0-compatible list check.
	 *
	 * @param array<mixed> $value Value to check.
	 */
	private static function isList( array $value ): bool {
		return array() === $value
			|| array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function transportError( \WP_Error $error ): \WP_Error {
		unset( $error );

		return new \WP_Error(
			'github_updater_http_transport_failed',
			'The GitHub request could not be completed.'
		);
	}

	private function httpError( int $statusCode ): \WP_Error {
		$code = 403 === $statusCode ? 'github_updater_github_forbidden' : 'github_updater_github_http_error';

		return new \WP_Error(
			$code,
			'GitHub returned an unexpected response.',
			array( 'status' => $statusCode )
		);
	}

	private function continuityError( string $identity ): \WP_Error {
		return new \WP_Error(
			'github_updater_artifact_continuity_failed',
			'The exact GitHub ' . $identity . ' changed after discovery.'
		);
	}
}
