<?php

namespace MediaWiki\Extension\CreateWikiLoadout;

use Exception;
use MediaWiki\Config\Config;
use MediaWiki\Shell\Shell;
use Psr\Log\LoggerInterface;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationExtraFieldsHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
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
					'dbname' => $dbname
				]
			);
			return;
		}

		try {
			$limits = [
				'memory' => 0,
				'filesize' => 0,
				'time' => 0,
				'walltime' => 0
			];
			$result = Shell::makeScriptCommand(
				'importDump',
				[
					'--wiki',
					$dbname,
					$xmlPath,
					'--username-prefix',
					'',
				]
			)->limits( $limits )->execute();

			if ( $result->getExitCode() !== 0 ) {
				$stderr = $result->getStderr();
				$this->logger->error(
					"ImportDump failed for wiki {dbname}: {error}",
					[
						'dbname' => $dbname,
						'error' => $stderr
					]
				);
			}
		} catch ( Exception $e ) {
			$this->logger->error(
				"Exception during importDump for wiki {dbname}: {exception}",
				[
					'dbname' => $dbname,
					'exception' => $e->getMessage()
				]
			);
		}
	}

	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		if ( $this->wikiLoadoutForm->isEnabled() ) {
			$loadoutDescriptor = $this->wikiLoadoutForm->getFormDescriptor();

			// Extremely ugly hack to put the loadout after "private" instead of at the very end of the form.
			$keys = array_keys( $formDescriptor );
			$privateIndex = array_search( 'private', $keys, true );

			if ( $privateIndex !== false ) {
				$before = array_slice( $formDescriptor, 0, $privateIndex + 1, true );
				$after = array_slice( $formDescriptor, $privateIndex + 1, null, true );
				$formDescriptor = array_merge( $before, [ 'loadout' => $loadoutDescriptor ], $after );
			} else {
				$formDescriptor['loadout'] = $loadoutDescriptor;
			}
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
