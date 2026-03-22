<?php

namespace MediaWiki\Extension\CreateWikiLoadout\Maintenance;

use ImportStreamSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;

class ImportLoadoutDump extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import an XML dump as part of a CreateWikiLoadout loadout.' );
		$this->addArg( 'file', 'Path to the XML dump file to import.' );
		$this->requireExtension( 'CreateWikiLoadout' );
	}

	public function execute(): void {
		$xmlPath = $this->getArg( 0 );
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$services = $this->getServiceContainer();

		$importStreamSource = ImportStreamSource::newFromFile( $xmlPath );
		if ( !$importStreamSource->isGood() ) {
			$statusFormatter = $services->getFormatterFactory()
				->getStatusFormatter( RequestContext::getMain() );
			$this->fatalError( "Failed to open XML dump file $xmlPath for wiki $dbname: " .
				$statusFormatter->getWikiText( $importStreamSource ) );
		}

		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();

		try {
			$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
			$importer = $services->getWikiImporterFactory()->getWikiImporter(
				$importStreamSource->value,
				new UltimateAuthority( $user )
			);

			$importer->disableStatisticsUpdate();
			$importer->setNoUpdates( true );
			$importer->setUsernamePrefix( '', true );

			$importer->doImport();

			$siteStatsInit = new SiteStatsInit();
			$siteStatsInit->refresh();

			SiteStatsUpdate::cacheUpdate( $dbw );

			$rebuildText = $this->createChild( RebuildTextIndex::class );
			$rebuildText->execute();

			$rebuildLinks = $this->createChild( RefreshLinks::class );
			$rebuildLinks->execute();
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->fatalError( "Exception during XML import for wiki $dbname: " . $t->getMessage() );
		}
	}
}

$maintClass = ImportLoadoutDump::class;
require_once RUN_MAINTENANCE_IF_MAIN;
