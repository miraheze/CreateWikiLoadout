<?php

namespace MediaWiki\Extension\CreateWikiLoadout\Jobs;

use ImportStreamSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Maintenance\FakeMaintenance;
use MediaWiki\Permissions\UltimateAuthority;
use MediaWiki\SiteStats\SiteStatsInit;
use MediaWiki\User\User;
use RebuildTextIndex;
use RefreshLinks;
use Throwable;
use WikiImporterFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportXmlDumpJob extends Job {

	public const JOB_NAME = 'CreateWikiLoadoutImportXmlDump';

	public function __construct(
		array $params,
		private readonly IConnectionProvider $connectionProvider,
		private readonly FormatterFactory $formatterFactory,
		private readonly WikiImporterFactory $wikiImporterFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );
	}

	public function run(): bool {
		$xmlPath = $this->params['xmlPath'];
		$dbname = $this->params['dbname'];

		// @phan-suppress-next-line SecurityCheck-PathTraversal False positive, path validated before job was pushed
		$importStreamSource = ImportStreamSource::newFromFile( $xmlPath );
		if ( !$importStreamSource->isGood() ) {
			$formatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
			$this->setLastError( "Failed to open XML dump file $xmlPath for wiki $dbname: " .
				$formatter->getWikiText( $importStreamSource, [ 'lang' => 'en' ] ) );
			return false;
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
			$this->setLastError( $t->getMessage() );
			return false;
		}

		return true;
	}
}
