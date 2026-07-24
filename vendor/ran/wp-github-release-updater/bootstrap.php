<?php
/**
 * Request-local bootstrap for the RAN WordPress GitHub Release Updater.
 *
 * A consuming plugin explicitly requires this file. No production class is
 * autoloaded until WordPress has loaded all active plugin main files and the
 * request-local broker has selected one compatible package candidate.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

// Public named arguments and the guarded broker protocol intentionally use
// the documented camelCase compatibility contract.
// phpcs:disable WordPress.NamingConventions.ValidVariableName
// phpcs:disable WordPress.NamingConventions.ValidFunctionName

$ran_wp_github_release_updater_candidate = array(
	'broker_protocol' => 1,
	'package_version' => '1.0.0-alpha.1-dev',
	'php_floor'       => '8.0.0',
	'wordpress_floor' => '6.5',
	'path'            => __DIR__,
	'runtime_file'    => __DIR__ . '/runtime.php',
);

$ran_wp_github_release_updater_existing_broker      =
	$GLOBALS['ran_wp_github_release_updater_v1_broker'] ?? null;
$ran_wp_github_release_updater_broker_methods       = array(
	'protocolVersion',
	'registerCandidate',
	'allocateRegistrationId',
	'registerTarget',
	'attachDiagnosticsProvider',
	'diagnostics',
);
$ran_wp_github_release_updater_broker_is_compatible =
	is_object( $ran_wp_github_release_updater_existing_broker );

foreach ( $ran_wp_github_release_updater_broker_methods as $ran_wp_github_release_updater_broker_method ) {
	if (
		! $ran_wp_github_release_updater_broker_is_compatible
		|| ! is_callable(
			array(
				$ran_wp_github_release_updater_existing_broker,
				$ran_wp_github_release_updater_broker_method,
			)
		)
	) {
		$ran_wp_github_release_updater_broker_is_compatible = false;
		break;
	}
}

if ( $ran_wp_github_release_updater_broker_is_compatible ) {
	try {
		$ran_wp_github_release_updater_protocol_callback    = array(
			$ran_wp_github_release_updater_existing_broker,
			'protocolVersion',
		);
		$ran_wp_github_release_updater_broker_is_compatible =
			is_callable( $ran_wp_github_release_updater_protocol_callback )
			&& 1 === $ran_wp_github_release_updater_protocol_callback();
	} catch ( Throwable ) {
		$ran_wp_github_release_updater_broker_is_compatible = false;
	}
}

if ( ! $ran_wp_github_release_updater_broker_is_compatible ) {
	$GLOBALS['ran_wp_github_release_updater_v1_broker'] = new class() {
		/**
		 * Registered package candidates, keyed by request-local ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $candidates = array();

		/**
		 * Registered updater targets, keyed by opaque registration ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $targets = array();

		/**
		 * Per-target passive diagnostics.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $targetDiagnostics = array();

		/**
		 * Passive runtime diagnostic providers, keyed by registration ID.
		 *
		 * @var array<string, callable(): array<string, mixed>>
		 */
		private array $diagnosticsProviders = array();

		/**
		 * Whether candidate selection has finished for this request.
		 *
		 * @var bool
		 */
		private bool $selectionFixed = false;

		/**
		 * Whether the broker was first loaded after plugins_loaded.
		 *
		 * @var bool
		 */
		private bool $loadedLate = false;

		/**
		 * Selected package version, when a runtime was loaded.
		 *
		 * @var string|null
		 */
		private ?string $selectedVersion = null;

		/**
		 * Candidate and target counters used only for opaque request-local IDs.
		 *
		 * @var int
		 */
		private int $candidateSequence = 0;

		/**
		 * Target counter.
		 *
		 * @var int
		 */
		private int $targetSequence = 0;

		/**
		 * Create the request-local broker and defer selection.
		 */
		public function __construct() {
			$this->loadedLate = function_exists( 'did_action' )
				&& did_action( 'plugins_loaded' ) > 0;

			if ( $this->loadedLate ) {
				$this->selectionFixed = true;
				return;
			}

			if ( function_exists( 'add_action' ) ) {
				add_action( 'plugins_loaded', array( $this, 'selectAndBoot' ), PHP_INT_MIN, 0 );
			}
		}

		/**
		 * Return the guarded broker protocol implemented by this object.
		 */
		public function protocolVersion(): int {
			return 1;
		}

		/**
		 * Register one physical package copy as a runtime candidate.
		 *
		 * Unknown candidate fields are retained for a later compatible broker.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 * @return string Opaque request-local candidate ID.
		 */
		public function registerCandidate( array $candidate ): string {
			$candidateId = 'candidate-' . ++$this->candidateSequence;

			if ( $this->selectionFixed || ! $this->isCandidateValid( $candidate ) ) {
				return $candidateId;
			}

			$candidate['candidate_id']        = $candidateId;
			$this->candidates[ $candidateId ] = $candidate;

			return $candidateId;
		}

		/**
		 * Allocate an opaque target registration ID for one facade.
		 *
		 * @param string $candidateId Candidate that created the facade.
		 * @return string Opaque request-local registration ID.
		 */
		public function allocateRegistrationId( string $candidateId ): string {
			return $candidateId . '-target-' . ++$this->targetSequence;
		}

		/**
		 * Submit one plain target record before runtime selection.
		 *
		 * @param array<string, mixed> $target Plain target record.
		 * @return bool Whether the target joined this request.
		 */
		public function registerTarget( array $target ): bool {
			$registrationId = $target['registrationId'] ?? null;

			if ( ! is_string( $registrationId ) || '' === $registrationId ) {
				return false;
			}

			if ( isset( $this->targets[ $registrationId ] ) ) {
				return true;
			}

			if ( $this->selectionFixed ) {
				$this->targetDiagnostics[ $registrationId ] = array(
					'code'  => 'late_registration',
					'state' => 'inactive',
				);

				return false;
			}

			$this->targets[ $registrationId ]           = $target;
			$this->targetDiagnostics[ $registrationId ] = array(
				'code'  => 'awaiting_runtime',
				'state' => 'registered',
			);

			return true;
		}

		/**
		 * Attach one passive runtime diagnostic provider to a known target.
		 *
		 * @param string   $registrationId Opaque target registration ID.
		 * @param callable $provider Passive provider invoked by diagnostics().
		 */
		public function attachDiagnosticsProvider(
			string $registrationId,
			callable $provider
		): bool {
			if (
				! isset( $this->targets[ $registrationId ] )
				|| isset( $this->diagnosticsProviders[ $registrationId ] )
			) {
				return false;
			}

			$this->diagnosticsProviders[ $registrationId ] = $provider;
			return true;
		}

		/**
		 * Return bounded request-local diagnostics without doing work.
		 *
		 * @param string $registrationId Facade registration ID.
		 * @param bool   $facadeRegistered Whether register() was called.
		 * @return array<string, mixed> Safe passive diagnostics.
		 */
		public function diagnostics( string $registrationId, bool $facadeRegistered ): array {
			$targetState = $this->targetDiagnostics[ $registrationId ] ?? array(
				'code'  => $this->loadedLate ? 'late_bootstrap' : 'not_registered',
				'state' => 'inactive',
			);

			$diagnostics = array(
				'registered'       => $facadeRegistered,
				'state'            => $targetState['state'],
				'code'             => $targetState['code'],
				'selection_fixed'  => $this->selectionFixed,
				'selected_version' => $this->selectedVersion,
				'candidate_count'  => count( $this->candidates ),
			);

			$provider = $this->diagnosticsProviders[ $registrationId ] ?? null;
			if ( ! is_callable( $provider ) ) {
				return $diagnostics;
			}

			try {
				$providerDiagnostics = $provider();
				if ( ! is_array( $providerDiagnostics ) ) {
					return $diagnostics;
				}

				return array_merge(
					$diagnostics,
					$this->sanitizeProviderDiagnostics( $providerDiagnostics )
				);
			} catch ( Throwable ) {
				$diagnostics['state'] = 'inactive';
				$diagnostics['code']  = 'diagnostics_provider_failed';
				return $diagnostics;
			}
		}

		/**
		 * Select one compatible runtime and synchronously hand it all targets.
		 *
		 * This is public only because WordPress invokes it as an action.
		 */
		public function selectAndBoot(): void {
			if ( $this->selectionFixed ) {
				return;
			}

			$this->selectionFixed = true;
			$candidate            = $this->selectCandidate();

			if ( null === $candidate ) {
				$this->markTargets( 'no_compatible_runtime', 'inactive' );
				return;
			}

			try {
				$runtimeFile = $candidate['runtime_file'];
				$entrypoint  = require $runtimeFile;

				if ( ! is_callable( $entrypoint ) ) {
					$this->markTargets( 'invalid_runtime_entrypoint', 'inactive' );
					return;
				}

				$this->selectedVersion = $candidate['package_version'];
				$entrypoint( array_values( $this->targets ) );
				$this->markTargets( 'runtime_selected', 'active' );
			} catch ( Throwable ) {
				$this->selectedVersion = null;
				$this->markTargets( 'runtime_load_failed', 'inactive' );
			}
		}

		/**
		 * Determine whether a candidate declaration is structurally safe.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 */
		private function isCandidateValid( array $candidate ): bool {
			return 1 === ( $candidate['broker_protocol'] ?? null )
				&& is_string( $candidate['package_version'] ?? null )
				&& '' !== $candidate['package_version']
				&& is_string( $candidate['php_floor'] ?? null )
				&& is_string( $candidate['wordpress_floor'] ?? null )
				&& is_string( $candidate['path'] ?? null )
				&& is_string( $candidate['runtime_file'] ?? null )
				&& is_file( $candidate['runtime_file'] );
		}

		/**
		 * Select the highest compatible candidate deterministically.
		 *
		 * Alpha 1 has one implementation protocol. Version/path comparison
		 * keeps same-version duplicate copies deterministic without claiming
		 * the later mixed-capability arbitration work is complete.
		 *
		 * @return array<string, mixed>|null
		 */
		private function selectCandidate(): ?array {
			$compatible = array_filter(
				$this->candidates,
				array( $this, 'isCandidateCompatible' )
			);

			if ( array() === $compatible ) {
				return null;
			}

			usort(
				$compatible,
				static function ( array $left, array $right ): int {
					$versionComparison = version_compare(
						(string) $right['package_version'],
						(string) $left['package_version']
					);

					if ( 0 !== $versionComparison ) {
						return $versionComparison;
					}

					return strcmp(
						(string) $left['path'],
						(string) $right['path']
					);
				}
			);

			return $compatible[0];
		}

		/**
		 * Test PHP and WordPress compatibility for one candidate.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 */
		private function isCandidateCompatible( array $candidate ): bool {
			if ( version_compare( PHP_VERSION, (string) $candidate['php_floor'], '<' ) ) {
				return false;
			}

			$wpVersion = $GLOBALS['wp_version'] ?? null;

			return ! is_string( $wpVersion )
				|| version_compare( $wpVersion, (string) $candidate['wordpress_floor'], '>=' );
		}

		/**
		 * Set one bounded diagnostic for every registered target.
		 *
		 * @param string $code Diagnostic code.
		 * @param string $state Target state.
		 */
		private function markTargets( string $code, string $state ): void {
			foreach ( array_keys( $this->targets ) as $registrationId ) {
				$this->targetDiagnostics[ $registrationId ] = array(
					'code'  => $code,
					'state' => $state,
				);
			}
		}

		/**
		 * Retain only bounded, path-free diagnostic fields from the runtime.
		 *
		 * @param array<string, mixed> $diagnostics Provider diagnostics.
		 * @return array<string, bool|int|string|null>
		 */
		private function sanitizeProviderDiagnostics( array $diagnostics ): array {
			$safe = array();

			foreach ( array( 'code', 'state' ) as $field ) {
				$value = $diagnostics[ $field ] ?? null;
				if (
					is_string( $value )
					&& 1 === preg_match( '/^[a-z0-9_.:-]{1,80}$/', $value )
				) {
					$safe[ $field ] = $value;
				}
			}

			$repository = $diagnostics['repository'] ?? null;
			if (
				is_string( $repository )
				&& 1 === preg_match(
					'/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/',
					$repository
				)
			) {
				$safe['repository'] = $repository;
			}

			$channel = $diagnostics['channel'] ?? null;
			if ( 'stable' === $channel || 'prerelease' === $channel ) {
				$safe['channel'] = $channel;
			}

			$plugin = $diagnostics['plugin'] ?? null;
			if (
				is_string( $plugin )
				&& ! str_contains( $plugin, '..' )
				&& 1 === preg_match(
					'/^(?:[A-Za-z0-9_.-]{1,100}\/)?[A-Za-z0-9_.-]{1,100}\.php$/',
					$plugin
				)
			) {
				$safe['plugin'] = $plugin;
			}

			$offeredVersion = $diagnostics['offered_version'] ?? null;
			if (
				null === $offeredVersion
				|| (
					is_string( $offeredVersion )
					&& 1 === preg_match( '/^[0-9A-Za-z.+-]{1,64}$/', $offeredVersion )
				)
			) {
				$safe['offered_version'] = $offeredVersion;
			}

			$lastCheck = $diagnostics['last_check'] ?? null;
			if ( null === $lastCheck || ( is_int( $lastCheck ) && $lastCheck >= 0 ) ) {
				$safe['last_check'] = $lastCheck;
			}

			foreach ( array( 'private_support', 'signing_support' ) as $field ) {
				if ( is_bool( $diagnostics[ $field ] ?? null ) ) {
					$safe[ $field ] = $diagnostics[ $field ];
				}
			}

			return $safe;
		}
	};
}

$ran_wp_github_release_updater_broker             = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
$ran_wp_github_release_updater_register_candidate = array(
	$ran_wp_github_release_updater_broker,
	'registerCandidate',
);
$ran_wp_github_release_updater_candidate_id       =
	is_callable( $ran_wp_github_release_updater_register_candidate )
		? $ran_wp_github_release_updater_register_candidate(
			$ran_wp_github_release_updater_candidate
		)
		: 'incompatible-broker';

return static function (
	string $pluginFile,
	string $repository,
	?string $pluginSlug = null,
	string $channel = 'stable',
	string|callable|null $accessToken = null,
	?string $assetPrefix = null,
	string|array|null $manifestPublicKey = null,
	string $autoUpdatePolicy = 'site-controlled',
	int $cacheDuration = 21_600,
	int $failureCacheDuration = 900,
	mixed ...$additionalOptions
) use (
	$ran_wp_github_release_updater_broker,
	$ran_wp_github_release_updater_candidate_id
): object {
	$repositorySeparator = strrpos( $repository, '/' );
	$repositoryName      = false === $repositorySeparator
		? $repository
		: substr( $repository, $repositorySeparator + 1 );
	$resolvedPluginSlug  = $pluginSlug ?? $repositoryName;

	$target                   = array_merge(
		$additionalOptions,
		array(
			'pluginFile'           => $pluginFile,
			'repository'           => $repository,
			'pluginSlug'           => $resolvedPluginSlug,
			'channel'              => $channel,
			'accessToken'          => $accessToken,
			'assetPrefix'          => $assetPrefix ?? $resolvedPluginSlug,
			'manifestPublicKey'    => $manifestPublicKey,
			'autoUpdatePolicy'     => $autoUpdatePolicy,
			'cacheDuration'        => $cacheDuration,
			'failureCacheDuration' => $failureCacheDuration,
		)
	);
	$allocateRegistrationId   = array(
		$ran_wp_github_release_updater_broker,
		'allocateRegistrationId',
	);
	$registrationId           = is_callable( $allocateRegistrationId )
		? $allocateRegistrationId(
			(string) $ran_wp_github_release_updater_candidate_id
		)
		: 'incompatible-broker-target';
	$target['registrationId'] = $registrationId;

	return new class(
		$ran_wp_github_release_updater_broker,
		$registrationId,
		$target
	) {
		/**
		 * Whether this facade has submitted its target.
		 *
		 * @var bool
		 */
		private bool $registered = false;

		/**
		 * Create one candidate-bound target facade.
		 *
		 * @param object               $broker Request-local broker.
		 * @param string               $registrationId Opaque target ID.
		 * @param array<string, mixed> $target Plain target configuration.
		 */
		public function __construct(
			private object $broker,
			private string $registrationId,
			private array $target
		) {
		}

		/**
		 * Idempotently submit this target to the request-local broker.
		 */
		public function register(): void {
			if ( $this->registered ) {
				return;
			}

			$this->registered = true;
			$registerTarget   = array( $this->broker, 'registerTarget' );
			if ( is_callable( $registerTarget ) ) {
				$registerTarget( $this->target );
			}
		}

		/**
		 * Return bounded passive state without causing a remote request.
		 *
		 * @return array<string, mixed>
		 */
		public function diagnostics(): array {
			$diagnostics = array( $this->broker, 'diagnostics' );

			if ( ! is_callable( $diagnostics ) ) {
				return array(
					'registered' => $this->registered,
					'state'      => 'inactive',
					'code'       => 'incompatible_broker',
				);
			}

			return (array) $diagnostics(
				$this->registrationId,
				$this->registered
			);
		}
	};
};
