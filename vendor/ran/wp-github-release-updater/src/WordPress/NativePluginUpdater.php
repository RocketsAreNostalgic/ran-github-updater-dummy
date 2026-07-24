<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\AssetNaming;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ConditionalState;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseListResult;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;

/**
 * Maps one configured plugin target onto WordPress Core's native update seams.
 *
 * @phpstan-type Offer array{
 *     release_id: int,
 *     tag: string,
 *     version: string,
 *     commit: string,
 *     zip_asset_id: int,
 *     zip_name: string,
 *     manifest_id: int,
 *     manifest_name: string,
 *     sha256: string,
 *     size: int,
 *     requires: string,
 *     requires_php: string,
 *     tested: string,
 *     details_url: string,
 *     package_url: string
 * }
 */
final class NativePluginUpdater {

	private const CACHE_SCHEMA = 1;

	private bool $registered = false;

	/** @var callable(): int */
	private $clock;

	/** @var array<string, mixed> */
	private array $pluginData;

	/**
	 * @param array<string, mixed> $pluginData Header-derived plugin metadata.
	 * @param callable(): int|null $clock      Injectable clock for focused tests.
	 */
	private function __construct(
		private ReleaseArtifactClient $artifacts,
		private string $pluginFile,
		private string $pluginBasename,
		private Repository $repository,
		private AssetNaming $assetNaming,
		private string $pluginSlug,
		private string $channel,
		private string $autoUpdatePolicy,
		private int $cacheDuration,
		private int $failureCacheDuration,
		array $pluginData,
		?callable $clock = null
	) {
		$this->pluginData = $pluginData;
		$this->clock      = $clock ?? static fn (): int => time();
	}

	/**
	 * Construct a validated public Alpha 1 updater from broker target data.
	 *
	 * @param array<string, mixed> $target Plain bootstrap target record.
	 * @return self|\WP_Error
	 */
	public static function fromTarget(
		array $target,
		ReleaseArtifactClient $artifacts,
		?callable $clock = null
	) {
		$pluginFile      = $target['pluginFile'] ?? null;
		$repository      = $target['repository'] ?? null;
		$pluginSlug      = $target['pluginSlug'] ?? null;
		$assetPrefix     = $target['assetPrefix'] ?? null;
		$channel         = $target['channel'] ?? null;
		$policy          = $target['autoUpdatePolicy'] ?? null;
		$cacheDuration   = $target['cacheDuration'] ?? null;
		$failureDuration = $target['failureCacheDuration'] ?? null;

		if ( ! is_string( $pluginFile )
			|| 1 !== preg_match( '#\A(?:/|[A-Za-z]:[\\\\/])#D', $pluginFile )
			|| ! is_file( $pluginFile )
		) {
			return self::configurationError( 'plugin_file', 'The consuming plugin file is invalid.' );
		}
		if ( ! is_string( $repository ) ) {
			return self::configurationError( 'repository', 'The GitHub repository is invalid.' );
		}
		$repositoryValue = Repository::fromString( $repository );
		if ( $repositoryValue instanceof \WP_Error ) {
			return $repositoryValue;
		}
		if ( ! is_string( $pluginSlug )
			|| 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $pluginSlug )
		) {
			return self::configurationError( 'plugin_slug', 'The canonical plugin slug is invalid.' );
		}
		if ( ! is_string( $assetPrefix ) ) {
			return self::configurationError( 'asset_prefix', 'The release asset prefix is invalid.' );
		}
		$assetNaming = AssetNaming::fromPrefix( $assetPrefix );
		if ( $assetNaming instanceof \WP_Error ) {
			return $assetNaming;
		}
		if ( ! is_string( $channel )
			|| ! in_array( $channel, array( ReleaseQuery::STABLE, ReleaseQuery::PRERELEASE ), true )
		) {
			return self::configurationError( 'channel', 'The release channel is invalid.' );
		}
		if ( ! is_string( $policy )
			|| ! in_array( $policy, array( 'site-controlled', 'forced-off', 'forced-on' ), true )
		) {
			return self::configurationError( 'auto_update_policy', 'The automatic-update policy is invalid.' );
		}
		if ( ! is_int( $cacheDuration ) || $cacheDuration < 300 || $cacheDuration > 86400 ) {
			return self::configurationError( 'cache_duration', 'The successful discovery cache duration is invalid.' );
		}
		if ( ! is_int( $failureDuration )
			|| $failureDuration < 60
			|| $failureDuration > 3600
			|| $failureDuration > $cacheDuration
		) {
			return self::configurationError( 'failure_cache_duration', 'The failure cache duration is invalid.' );
		}

		// Alpha 1 intentionally proves public unsigned behavior only.
		if ( null !== ( $target['accessToken'] ?? null )
			|| null !== ( $target['manifestPublicKey'] ?? null )
		) {
			return new \WP_Error(
				'github_updater_alpha_feature_unavailable',
				'Private authentication and manifest signing are not available in Alpha 1.'
			);
		}

		$pluginData = self::readPluginData( $pluginFile );
		if ( $pluginData instanceof \WP_Error ) {
			return $pluginData;
		}
		$expectedUpdateUri = 'https://github.com/' . $repositoryValue->canonical();
		$updateUri         = is_string( $pluginData['UpdateURI'] ?? null )
			? rtrim( $pluginData['UpdateURI'], '/' )
			: '';
		if ( $expectedUpdateUri !== $updateUri ) {
			return self::configurationError(
				'update_uri',
				'The consuming plugin Update URI does not match its configured GitHub repository.'
			);
		}

		$pluginBasename     = str_replace( '\\', '/', plugin_basename( $pluginFile ) );
		$installedDirectory = dirname( $pluginBasename );
		if ( '.' === $installedDirectory || $pluginSlug !== $installedDirectory ) {
			return new \WP_Error(
				'github_updater_renamed_directory_unsupported',
				'Alpha 1 will not move a renamed plugin directory during an update.'
			);
		}

		return new self(
			$artifacts,
			$pluginFile,
			$pluginBasename,
			$repositoryValue,
			$assetNaming,
			$pluginSlug,
			$channel,
			$policy,
			$cacheDuration,
			$failureDuration,
			$pluginData,
			$clock
		);
	}

	/**
	 * Idempotently register only the documented native Core hooks.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		add_filter( 'update_plugins_github.com', array( $this, 'filterUpdate' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filterPluginInformation' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( $this, 'filterAutoUpdate' ), 10, 2 );
		add_filter(
			'upgrader_pre_download',
			array( $this, 'filterPreDownload' ),
			PHP_INT_MAX,
			4
		);
		add_action( 'upgrader_process_complete', array( $this, 'observeCompletion' ), 10, 2 );
		$this->registered = true;
	}

	/**
	 * Supply native update metadata for this exact plugin only.
	 *
	 * @param array<string, mixed>|false $update Existing host update.
	 * @param array<string, mixed>       $pluginData Current plugin headers.
	 * @param list<string>               $locales Requested locales.
	 * @return array<string, mixed>|false
	 */
	public function filterUpdate( $update, array $pluginData, string $pluginFile, array $locales ) {
		unset( $locales );
		if ( $this->pluginBasename !== $pluginFile ) {
			return $update;
		}

		$currentVersion = is_string( $pluginData['Version'] ?? null )
			? $pluginData['Version']
			: '';
		if ( '' === $currentVersion ) {
			$this->storeDiagnostic( 'missing_installed_version', 'inactive' );
			return false;
		}

		$offer = $this->offer();
		if ( null === $offer || version_compare( $offer['version'], $currentVersion, '<=' ) ) {
			return false;
		}

		return array(
			'id'           => 'https://github.com/' . $this->repository->canonical(),
			'slug'         => $this->pluginSlug,
			'plugin'       => $this->pluginBasename,
			'version'      => $offer['version'],
			'url'          => $offer['details_url'],
			'package'      => $offer['package_url'],
			'tested'       => $offer['tested'],
			'requires'     => $offer['requires'],
			'requires_php' => $offer['requires_php'],
			'autoupdate'   => 'forced-on' === $this->autoUpdatePolicy,
		);
	}

	/**
	 * Supply a lean native plugin-information response.
	 */
	public function filterPluginInformation( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action
			|| ! is_object( $arguments )
			|| ( $arguments->slug ?? null ) !== $this->pluginSlug
		) {
			return $result;
		}

		$offer  = $this->offer();
		$object = new \stdClass();

		$object->name          = $this->header( 'Name', $this->pluginSlug );
		$object->slug          = $this->pluginSlug;
		$object->version       = $offer['version'] ?? $this->header( 'Version', '' );
		$object->author        = $this->header( 'Author', '' );
		$object->homepage      = $this->header( 'PluginURI', 'https://github.com/' . $this->repository->canonical() );
		$object->requires      = $offer['requires'] ?? $this->header( 'RequiresWP', '' );
		$object->tested        = $offer['tested'] ?? '';
		$object->requires_php  = $offer['requires_php'] ?? $this->header( 'RequiresPHP', '' );
		$object->download_link = $offer['package_url'] ?? '';
		$object->external      = true;
		$object->sections      = array(
			'description' => $this->header( 'Description', '' ),
			'changelog'   => null === $offer
				? ''
				: 'Release ' . $offer['version'] . ' is available from GitHub.',
		);

		return $object;
	}

	/**
	 * Preserve or narrowly override Core's automatic-update decision.
	 */
	public function filterAutoUpdate( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ( $item->plugin ?? null ) !== $this->pluginBasename ) {
			return $update;
		}
		if ( 'forced-off' === $this->autoUpdatePolicy ) {
			return false;
		}
		if ( 'forced-on' === $this->autoUpdatePolicy ) {
			return true;
		}

		return $update;
	}

	/**
	 * Admit only the exact offered asset and return its verified local path.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function filterPreDownload(
		mixed $reply,
		string $package,
		mixed $upgrader,
		array $hookExtra
	): mixed {
		unset( $upgrader );
		if ( ( $hookExtra['plugin'] ?? null ) !== $this->pluginBasename ) {
			return $reply;
		}
		if ( $reply instanceof \WP_Error ) {
			return $reply;
		}
		if ( false !== $reply ) {
			return $this->downloadError(
				'github_updater_unverified_pre_download_result',
				'An earlier download handler returned an unverified package for this plugin.'
			);
		}

		$state = $this->cachedState();
		$offer = $this->validatedOffer( $state['offer'] ?? null );
		if ( null === $offer || ! hash_equals( $offer['package_url'], $package ) ) {
			return $this->downloadError(
				'github_updater_unverified_update',
				'The update package does not match the exact offered GitHub Release asset.'
			);
		}

		$query      = $this->query();
		$descriptor = $this->artifacts->describeExact(
			new ExactReleaseRequest( $query, $offer['release_id'], $offer['tag'] )
		);
		if ( $descriptor instanceof \WP_Error
			|| ! $this->descriptorMatchesOffer( $descriptor, $offer )
		) {
			return $this->downloadError(
				'github_updater_release_changed',
				'The offered GitHub Release changed before download.'
			);
		}

		$verified = $this->artifacts->acquire( $descriptor );
		if ( $verified instanceof \WP_Error ) {
			$this->storeDiagnostic( self::errorCode( $verified ), 'failed' );
			return $verified;
		}
		$claimed = $verified->claim();
		if ( $claimed instanceof \WP_Error ) {
			$this->storeDiagnostic( self::errorCode( $claimed ), 'failed' );
			return $claimed;
		}

		$this->storeDiagnostic( 'verified_download', 'ready' );
		return $claimed->path();
	}

	/**
	 * Clear only this target's cached offer after a successful Core update.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function observeCompletion( mixed $upgrader, array $hookExtra ): void {
		$result = is_object( $upgrader ) && property_exists( $upgrader, 'result' )
			? $upgrader->result
			: true;
		if ( $result instanceof \WP_Error || false === $result ) {
			return;
		}
		if ( 'update' !== ( $hookExtra['action'] ?? null )
			|| 'plugin' !== ( $hookExtra['type'] ?? null )
		) {
			return;
		}
		$plugins = $hookExtra['plugins'] ?? array( $hookExtra['plugin'] ?? null );
		if ( ! is_array( $plugins ) || ! in_array( $this->pluginBasename, $plugins, true ) ) {
			return;
		}

		delete_site_transient( $this->cacheKey() );
		$this->storeDiagnostic( 'update_completed', 'current' );
	}

	/**
	 * Return bounded passive state without initiating remote work.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array {
		$state = $this->cachedState();
		$offer = $this->validatedOffer( $state['offer'] ?? null );

		return array(
			'registered'      => $this->registered,
			'code'            => is_string( $state['diagnostic']['code'] ?? null )
				? $state['diagnostic']['code']
				: 'not_checked',
			'state'           => is_string( $state['diagnostic']['state'] ?? null )
				? $state['diagnostic']['state']
				: 'idle',
			'repository'      => $this->repository->canonical(),
			'channel'         => $this->channel,
			'plugin'          => $this->pluginBasename,
			'offered_version' => $offer['version'] ?? null,
			'last_check'      => is_int( $state['checked_at'] ?? null )
				? $state['checked_at']
				: null,
			'private_support' => false,
			'signing_support' => false,
		);
	}

	/**
	 * Return a fresh or cached normalized exact offer.
	 *
	 * @return Offer|null
	 */
	private function offer(): ?array {
		$state         = $this->cachedState();
		$offer         = $this->validatedOffer( $state['offer'] ?? null );
		$age           = is_int( $state['checked_at'] ?? null )
			? ( $this->now() - $state['checked_at'] )
			: PHP_INT_MAX;
		$cooldownUntil = is_int( $state['cooldown_until'] ?? null )
			? $state['cooldown_until']
			: 0;
		if ( $cooldownUntil > $this->now() ) {
			return null !== $offer && $age <= $this->cacheDuration + ( $cooldownUntil - $this->now() )
				? $offer
				: null;
		}
		if ( null !== $offer && $age >= 0 && $age < $this->cacheDuration ) {
			return $offer;
		}
		if ( 'unavailable' === ( $state['status'] ?? null )
			&& is_int( $state['failed_at'] ?? null )
			&& ( $this->now() - $state['failed_at'] ) < $this->failureCacheDuration
		) {
			return null;
		}

		$conditional = $this->conditionalFromState( $state );
		$query       = $this->query( $conditional );
		$list        = $this->artifacts->listReleases( $query );
		if ( $list instanceof \WP_Error ) {
			$this->storeUnavailable( self::errorCode( $list ), $conditional, $state );
			return null;
		}
		if ( $list->rateLimit()->isLimited() ) {
			return $this->storeRateLimited( $state, $list );
		}
		if ( $list->isNotModified() ) {
			if ( null === $offer ) {
				$this->storeUnavailable(
					'not_modified_without_cached_offer',
					$list->conditional(),
					array()
				);
				return null;
			}
			$this->storeAvailable(
				$offer,
				$this->mergedConditional( $list->conditional(), $conditional )
			);
			return $offer;
		}

		$descriptor = $this->selectDescriptor( $list, $query );
		if ( $descriptor instanceof \WP_Error ) {
			$this->storeUnavailable( self::errorCode( $descriptor ), $list->conditional(), array() );
			return null;
		}
		if ( null === $descriptor ) {
			$this->storeUnavailable( 'no_eligible_release', $list->conditional(), array() );
			return null;
		}

		$offer = $this->offerFromDescriptor( $descriptor );
		$this->storeAvailable( $offer, $list->conditional() );
		return $offer;
	}

	/**
	 * Resolve candidates in order, permitting fallback only for incompatibility.
	 *
	 * @return ArtifactDescriptor|\WP_Error|null
	 */
	private function selectDescriptor(
		ReleaseListResult $releaseList,
		ReleaseQuery $query
	) {
		foreach ( $releaseList->releases() as $release ) {
			$descriptor = $this->artifacts->describeExact(
				new ExactReleaseRequest( $query, $release->releaseId(), $release->tag() )
			);
			if ( $descriptor instanceof \WP_Error ) {
				if ( 'github_updater_release_incompatible' === self::errorCode( $descriptor ) ) {
					continue;
				}
				return $descriptor;
			}
			if ( ReleaseQuery::STABLE === $this->channel && $descriptor->isPrerelease() ) {
				continue;
			}

			return $descriptor;
		}

		return null;
	}

	private function query( ?ConditionalState $conditional = null ): ReleaseQuery {
		$wpVersion = is_string( $GLOBALS['wp_version'] ?? null )
			? $GLOBALS['wp_version']
			: '6.5';

		return new ReleaseQuery(
			$this->repository,
			$this->assetNaming,
			$this->pluginSlug,
			basename( $this->pluginFile ),
			$this->channel,
			PHP_VERSION,
			$wpVersion,
			5,
			$conditional
		);
	}

	/**
	 * @return Offer
	 */
	private function offerFromDescriptor( ArtifactDescriptor $descriptor ): array {
		$manifest = $descriptor->manifest();
		return array(
			'release_id'    => $descriptor->releaseId(),
			'tag'           => $descriptor->tag(),
			'version'       => $descriptor->version(),
			'commit'        => $descriptor->commit(),
			'zip_asset_id'  => $descriptor->zipAsset()->id(),
			'zip_name'      => $descriptor->zipAsset()->name(),
			'manifest_id'   => $descriptor->manifestAsset()->id(),
			'manifest_name' => $descriptor->manifestAsset()->name(),
			'sha256'        => $manifest->zipSha256(),
			'size'          => $manifest->zipSize(),
			'requires'      => $manifest->requiresWordPress(),
			'requires_php'  => $manifest->requiresPhp(),
			'tested'        => $manifest->testedWordPress(),
			'details_url'   => $descriptor->detailsUrl(),
			'package_url'   => 'https://api.github.com/repos/'
				. $this->repository->apiPath()
				. '/releases/assets/'
				. $descriptor->zipAsset()->id(),
		);
	}

	/**
	 * @param Offer $offer
	 */
	private function descriptorMatchesOffer( ArtifactDescriptor $descriptor, array $offer ): bool {
		$current = $this->offerFromDescriptor( $descriptor );
		foreach (
			array(
				'release_id',
				'tag',
				'version',
				'commit',
				'zip_asset_id',
				'zip_name',
				'manifest_id',
				'manifest_name',
				'sha256',
				'size',
				'package_url',
			) as $key
		) {
			if ( $current[ $key ] !== $offer[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return Offer|null
	 */
	private function validatedOffer( mixed $offer ): ?array {
		if ( ! is_array( $offer ) ) {
			return null;
		}
		$stringFields = array(
			'tag',
			'version',
			'commit',
			'zip_name',
			'manifest_name',
			'sha256',
			'requires',
			'requires_php',
			'tested',
			'details_url',
			'package_url',
		);
		foreach ( $stringFields as $key ) {
			if ( ! is_string( $offer[ $key ] ?? null ) || '' === $offer[ $key ] ) {
				return null;
			}
		}
		foreach ( array( 'release_id', 'zip_asset_id', 'manifest_id', 'size' ) as $key ) {
			if ( ! is_int( $offer[ $key ] ?? null ) || $offer[ $key ] < 1 ) {
				return null;
			}
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $offer['commit'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $offer['sha256'] )
			|| ! str_starts_with( $offer['details_url'], 'https://github.com/' . $this->repository->canonical() . '/releases/' )
			|| ! str_starts_with( $offer['package_url'], 'https://api.github.com/repos/' . $this->repository->apiPath() . '/releases/assets/' )
		) {
			return null;
		}

		/** @var Offer $offer */
		return $offer;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cachedState(): array {
		$state = get_site_transient( $this->cacheKey() );
		return is_array( $state ) && self::CACHE_SCHEMA === ( $state['schema'] ?? null )
			? $state
			: array();
	}

	/**
	 * @param Offer $offer
	 */
	private function storeAvailable( array $offer, ConditionalState $conditional ): void {
		set_site_transient(
			$this->cacheKey(),
			array(
				'schema'      => self::CACHE_SCHEMA,
				'status'      => 'available',
				'checked_at'  => $this->now(),
				'offer'       => $offer,
				'conditional' => $this->conditionalToArray( $conditional ),
				'diagnostic'  => array(
					'code'  => 'release_available',
					'state' => 'ready',
				),
			),
			$this->cacheDuration + 86400
		);
	}

	/**
	 * @param array<string, mixed> $priorState Last safe cached state.
	 */
	private function storeUnavailable(
		string $code,
		ConditionalState $conditional,
		array $priorState
	): void {
		$offer     = $this->validatedOffer( $priorState['offer'] ?? null );
		$checkedAt = is_int( $priorState['checked_at'] ?? null )
			? $priorState['checked_at']
			: null;
		set_site_transient(
			$this->cacheKey(),
			array(
				'schema'      => self::CACHE_SCHEMA,
				'status'      => 'unavailable',
				'checked_at'  => $checkedAt,
				'failed_at'   => $this->now(),
				'offer'       => $offer,
				'conditional' => $this->conditionalToArray(
					$this->mergedConditional(
						$conditional,
						$this->conditionalFromState( $priorState )
					)
				),
				'diagnostic'  => array(
					'code'  => sanitize_key( $code ),
					'state' => 'unavailable',
				),
			),
			$this->cacheDuration + 86400
		);
	}

	/**
	 * Persist a provider cooldown without discarding a still-bounded safe offer.
	 *
	 * @param array<string, mixed> $priorState Last safe cached state.
	 * @return Offer|null
	 */
	private function storeRateLimited( array $priorState, ReleaseListResult $releaseList ): ?array {
		$cooldown = $releaseList->rateLimit()->cooldownSeconds() ?? $this->failureCacheDuration;
		$cooldown = max( 1, min( 86400, $cooldown ) );
		$offer    = $this->validatedOffer( $priorState['offer'] ?? null );
		$state    = array(
			'schema'         => self::CACHE_SCHEMA,
			'status'         => 'rate_limited',
			'checked_at'     => is_int( $priorState['checked_at'] ?? null )
				? $priorState['checked_at']
				: null,
			'failed_at'      => $this->now(),
			'cooldown_until' => $this->now() + $cooldown,
			'offer'          => $offer,
			'conditional'    => $this->conditionalToArray(
				$this->mergedConditional(
					$releaseList->conditional(),
					$this->conditionalFromState( $priorState )
				)
			),
			'diagnostic'     => array(
				'code'  => 'rate_limited',
				'state' => 'cooldown',
			),
		);
		set_site_transient( $this->cacheKey(), $state, $this->cacheDuration + $cooldown );

		$age = is_int( $state['checked_at'] )
			? $this->now() - $state['checked_at']
			: PHP_INT_MAX;
		return null !== $offer && $age <= $this->cacheDuration + $cooldown
			? $offer
			: null;
	}

	private function storeDiagnostic( string $code, string $diagnosticState ): void {
		$state               = $this->cachedState();
		$state['schema']     = self::CACHE_SCHEMA;
		$state['diagnostic'] = array(
			'code'  => substr( sanitize_key( $code ), 0, 80 ),
			'state' => sanitize_key( $diagnosticState ),
		);
		set_site_transient( $this->cacheKey(), $state, $this->cacheDuration );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function conditionalFromState( array $state ): ConditionalState {
		$conditional = is_array( $state['conditional'] ?? null )
			? $state['conditional']
			: array();
		return new ConditionalState(
			is_string( $conditional['etag'] ?? null ) ? $conditional['etag'] : null,
			is_string( $conditional['last_modified'] ?? null ) ? $conditional['last_modified'] : null
		);
	}

	/**
	 * @return array{etag: ?string, last_modified: ?string}
	 */
	private function conditionalToArray( ConditionalState $conditional ): array {
		return array(
			'etag'          => $conditional->etag(),
			'last_modified' => $conditional->lastModified(),
		);
	}

	private function mergedConditional(
		ConditionalState $fresh,
		ConditionalState $prior
	): ConditionalState {
		return new ConditionalState(
			$fresh->etag() ?? $prior->etag(),
			$fresh->lastModified() ?? $prior->lastModified()
		);
	}

	private function cacheKey(): string {
		return 'ran_wp_gh_updater_v1_' . substr(
			hash(
				'sha256',
				$this->repository->canonical()
				. "\0"
				. $this->pluginBasename
				. "\0"
				. $this->channel
			),
			0,
			32
		);
	}

	private function now(): int {
		return ( $this->clock )();
	}

	private function header( string $key, string $fallback ): string {
		return is_string( $this->pluginData[ $key ] ?? null )
			? $this->pluginData[ $key ]
			: $fallback;
	}

	private function downloadError( string $code, string $message ): \WP_Error {
		$this->storeDiagnostic( $code, 'failed' );
		return new \WP_Error( $code, $message );
	}

	private static function errorCode( \WP_Error $error ): string {
		$callable = array( $error, 'get_error_code' );
		if ( ! is_callable( $callable ) ) {
			return 'github_updater_error';
		}

		$code = call_user_func( $callable );
		return is_string( $code ) && '' !== $code
			? $code
			: 'github_updater_error';
	}

	private static function configurationError( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'github_updater_invalid_' . $field, $message );
	}

	/**
	 * Read standard plugin headers without assuming wp-admin helpers are loaded.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function readPluginData( string $pluginFile ) {
		if ( function_exists( 'get_plugin_data' ) ) {
			return get_plugin_data( $pluginFile, false, false );
		}
		if ( ! function_exists( 'get_file_data' ) ) {
			return self::configurationError(
				'plugin_metadata',
				'WordPress plugin metadata functions are unavailable.'
			);
		}

		return get_file_data(
			$pluginFile,
			array(
				'Name'        => 'Plugin Name',
				'PluginURI'   => 'Plugin URI',
				'Version'     => 'Version',
				'Description' => 'Description',
				'Author'      => 'Author',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
				'UpdateURI'   => 'Update URI',
			),
			'plugin'
		);
	}
}
