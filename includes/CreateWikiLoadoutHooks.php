<?php

namespace MediaWiki\Extension\CreateWikiLoadout;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CreateWikiLoadout\Jobs\ImportXmlDumpJob;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use Psr\Log\LoggerInterface;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationExtraFieldsHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Services\WikiRequestManager;

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
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
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

		$this->jobQueueGroupFactory->makeJobQueueGroup( $dbname )->push(
			new JobSpecification(
				ImportXmlDumpJob::JOB_NAME,
				[
					'xmlPath' => $xmlPath,
					'dbname' => $dbname,
				]
			)
		);
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

}
