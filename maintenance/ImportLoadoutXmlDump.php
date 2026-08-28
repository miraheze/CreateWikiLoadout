<?php

namespace MediaWiki\Extension\CreateWikiLoadout\Maintenance;

use Exception;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\Shell\Shell;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;

class ImportLoadoutXmlDump extends Maintenance {

	private LoggerInterface $logger;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Imports a CreateWikiLoadout XML dump into this wiki.' );
		$this->addOption( 'xml', 'Path to the XML dump to import.', true, true );

		$this->requireExtension( 'CreateWikiLoadout' );
	}

	public function execute(): void {
		$this->logger = LoggerFactory::getInstance( 'CreateWiki' );
		$this->logger->info( 'CreateWikiLoadout import started.' );

		$xmlPath = $this->getOption( 'xml' );

		if ( !file_exists( $xmlPath ) || !is_readable( $xmlPath ) ) {
			$this->fatalError( "XML dump file $xmlPath not found or not readable." );
		}

		$dbw = $this->getPrimaryDB();

		try {
			$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
			$this->deleteDefaultMainPage( $user );
			$this->logger->info( 'CreateWikiLoadout deleted the old main page.' );

			$result = Shell::makeScriptCommand(
				'importDump.php',
				[
					'--no-updates',
					'--no-local-users',
					'--wiki', $this->getConfig()->get( MainConfigNames::DBname ),
					$xmlPath,
				]
			)->limits( [
				'memory' => 0,
				'filesize' => 0,
				'time' => 0,
				'walltime' => 0,
			] )->execute();

			if ( $result->getExitCode() !== 0 ) {
				$this->fatalError( 'CreateWikiLoadout failed to import the dump file: ' . $result->getStderr() );
			}

			$this->logger->info( 'CreateWikiLoadout finished importing the XML dump.' );

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );
			$this->logger->info( 'CreateWikiLoadout finished updateSiteStats.' );

			$maintenance = new FakeMaintenance;
			$rebuildText = $maintenance->createChild( RebuildTextIndex::class );
			$rebuildText->execute();
			$this->logger->info( 'CreateWikiLoadout finished rebuildTextIndex.' );

			$rebuildLinks = $maintenance->createChild( RefreshLinks::class );
			$rebuildLinks->execute();
			$this->logger->info( 'CreateWikiLoadout finished refreshLinks.' );
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->fatalError( $t->getMessage() );
		}

		$this->logger->info( 'CreateWikiLoadout import finished.' );
	}

	private function deleteDefaultMainPage( User $user ): void {
		$services = $this->getServiceContainer();

		$page = $services->getWikiPageFactory()->newFromTitle( Title::newMainPage() );
		$page->loadPageData( IDBAccessObject::READ_LATEST );
		if ( !$page->exists() ) {
			$this->logger->warning( 'CreateWikiLoadout expected a default main page to exist but there was none.' );
			return;
		}

		$services->getDeletePageFactory()->newDeletePage( $page, new UltimateAuthority( $user ) )
			->forceImmediate( true )
			->deleteUnsafe( 'Delete default main page to make way for CreateWikiLoadout import.' );
	}
}

// @codeCoverageIgnoreStart
return ImportLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
