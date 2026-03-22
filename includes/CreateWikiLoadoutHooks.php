<?php

namespace MediaWiki\Extension\CreateWikiLoadout;

use Exception;
use ImportStreamSource;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use Psr\Log\LoggerInterface;
use RebuildTextIndex;
use RefreshLinks;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationExtraFieldsHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Throwable;
use WikiImporterFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class CreateWikiLoadoutHooks implements
	CreateWikiAfterCreationWithExtraDataHook,
	CreateWikiCreationExtraFieldsHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook {

	public function __construct(
		private readonly WikiLoadoutForm $wikiLoadoutForm,
		private readonly Config $config,
		private readonly ModuleFactory $moduleFactory,
		private readonly LoggerInterface $logger,
		private readonly WikiImporterFactory $wikiImporterFactory,
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	public function onCreateWikiCreationExtraFields( array &$extraFields ): void {
		$extraFields[] = 'loadout';
	}

	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		if ( empty( $extraData['loadout'] ) ) {
			return;
		}

		$loadouts = $this->config->get( 'CreateWikiLoadoutConfigs' );
		if ( !isset( $loadouts[$extraData['loadout']] ) ) {
			$this->logger->error(
				"Invalid loadout '{loadout}' specified for wiki {dbname}",
				[
					'loadout' => $extraData['loadout'],
					'dbname' => $dbname
				]
			);
			return;
		}

		$loadoutConfig = $loadouts[$extraData['loadout']];

		if ( isset( $loadoutConfig['extensions'] ) ) {
			$this->processExtensions( $dbname, $loadoutConfig['extensions'] );
		}

		if ( isset( $loadoutConfig['settings'] ) ) {
			$this->processSettings( $dbname, $loadoutConfig['settings'] );
		}

		if ( !isset( $loadoutConfig['xml'] ) || !$loadoutConfig['xml'] ) {
			// No XML file. This is fine because sometimes we just want to tweak extensions and or settings.
			return;
		}

		$xmlPath = $loadoutConfig['xml'];

		if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
			$this->logger->error(
				"XML dump file {path} not found or not readable",
				[
					'path' => $xmlPath,
					'dbname' => $dbname,
				]
			);
			return;
		}

		$this->performImport( $xmlPath, $dbname );
	}

	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		if ( $this->wikiLoadoutForm->isEnabled() ) {
			RequestWikiFormUtils::insertFieldAfter(
				$formDescriptor,
				afterKey: 'category',
				newKey: 'loadout',
				newField: $this->wikiLoadoutForm->getFormDescriptor()
			);
		}
	}

	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		if ( $this->wikiLoadoutForm->isEnabled() ) {
			$loadout = $wikiRequestManager->getExtraFieldData( 'loadout' );
			if ( $loadout ) {
				$formDescriptor['loadout'] = [
					'type' => 'info',
					'label-message' => 'cwloadout-label-loadout',
					'section' => 'details',
					'default' => $loadout,
				];
			}

			$loadoutDescriptor = $this->wikiLoadoutForm->getFormDescriptor();
			$loadoutDescriptor['default'] = $wikiRequestManager->getExtraFieldData( 'loadout' ) ?? '';
			$loadoutDescriptor['disabled'] = $wikiRequestManager->isLocked();
			$loadoutDescriptor['section'] = 'editing';
			$formDescriptor['edit-loadout'] = $loadoutDescriptor;
		}
	}

	private function processExtensions( string $dbname, array $extensions ): void {
		try {
			$mwExtensions = $this->moduleFactory->extensions( $dbname );
			$mwExtensions->add( $extensions );
			$mwExtensions->commit();
		} catch ( Exception $e ) {
			$this->logger->error(
				"Failed to enable extensions for wiki $dbname",
				[
					'extensions' => $extensions,
					'exception' => $e->getMessage()
				]
			);
		}
	}

	private function processSettings( string $dbname, array $settings ): void {
		try {
			$mwSettings = $this->moduleFactory->settings( $dbname );
			$mwSettings->modify( $settings, default: null );
			$mwSettings->commit();
		} catch ( Exception $e ) {
			$this->logger->error(
				"Failed to set settings for wiki $dbname",
				[
					'settings' => $settings,
					'exception' => $e->getMessage()
				]
			);
		}
	}

	public function performImport( string $xmlPath, string $dbname ): void {
		$importStreamSource = ImportStreamSource::newFromFile( $xmlPath );
		if ( !$importStreamSource->isGood() ) {
			$this->logger->error(
				"Failed to open XML dump file {path} for wiki {dbname}: {error}",
				[
					'path' => $xmlPath,
					'dbname' => $dbname,
					'error' => $importStreamSource->getMessages(),
				]
			);
			return;
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase();

		try {
			$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
			$importer = $this->wikiImporterFactory->getWikiImporter(
				$importStreamSource->value,
				new UltimateAuthority( $user )
			);

			$importer->disableStatisticsUpdate();
			$importer->setNoUpdates( true );
			// assignKnownUsers is always useless because there will only be a single user
			$importer->setUsernamePrefix( '', true );

			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$maintenance = new FakeMaintenance;
			$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
			$rebuildText->execute();

			$rebuildLinks = $maintenance->createChild( RefreshLinks::class );
			$rebuildLinks->execute();
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->logger->error(
				"Exception during XML import for wiki {dbname}: {exception}",
				[
					'dbname' => $dbname,
					'exception' => $t->getMessage(),
				]
			);
		}
	}
}
