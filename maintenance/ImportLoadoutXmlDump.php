<?php

namespace MediaWiki\Extension\CreateWikiLoadout\Maintenance;

use ImportStreamSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;

class ImportLoadoutXmlDump extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Imports a CreateWikiLoadout XML dump into this wiki.' );
		$this->addOption( 'xml', 'Path to the XML dump to import.', true, true );

		$this->requireExtension( 'CreateWikiLoadout' );
	}

	public function execute(): void {
		$xmlPath = $this->getOption( 'xml' );
		$services = $this->getServiceContainer();

		// @phan-suppress-next-line SecurityCheck-PathTraversal False positive, path comes from config
		$importStreamSource = ImportStreamSource::newFromFile( $xmlPath );
		if ( !$importStreamSource->isGood() ) {
			$formatter = $services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
			$this->fatalError(
				"Failed to open XML dump file $xmlPath: " .
				$formatter->getWikiText( $importStreamSource, [ 'lang' => 'en' ] )
			);
		}

		$dbw = $this->getPrimaryDB();

		try {
			$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
			$this->deleteDefaultMainPage( $user );
			$importer = $services->getWikiImporterFactory()->getWikiImporter(
				$importStreamSource->value,
				new UltimateAuthority( $user )
			);

			$importer->disableStatisticsUpdate();
			$importer->setNoUpdates( true );
			// assignKnownUsers is always useless because there will only be a single user in the XML dump
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
			$this->fatalError( $t->getMessage() );
		}
	}

	private function deleteDefaultMainPage( User $user ): void {
		$services = $this->getServiceContainer();
		$logger = LoggerFactory::getInstance( 'CreateWiki' );

		$page = $services->getWikiPageFactory()->newFromTitle( Title::newMainPage() );
		$page->loadPageData( IDBAccessObject::READ_LATEST );
		if ( !$page->exists() ) {
			$logger->warning( 'CreateWikiLoadout expected a default main page to exist but there was none.' );
			return;
		}

		$services->getDeletePageFactory()->newDeletePage( $page, new UltimateAuthority( $user ) )
			->forceImmediate( true )
			->deleteUnsafe( 'Delete default main page to make way for CreateWikiLoadout import.' );
		$logger->info( 'Default main page deleted by CreateWikiLoadout.' );
	}
}

// @codeCoverageIgnoreStart
return ImportLoadoutXmlDump::class;
// @codeCoverageIgnoreEnd
