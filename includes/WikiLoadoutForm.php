<?php

namespace MediaWiki\Extension\CreateWikiLoadout;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;

class WikiLoadoutForm {

	public const CONSTRUCTOR_OPTIONS = [
		'CreateWikiLoadoutEnabled',
		'CreateWikiLoadoutConfigs',
	];

	private readonly array $loadoutOptions;

	public function __construct( Config $config ) {
		$options = new ServiceOptions( self::CONSTRUCTOR_OPTIONS, $config );
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->loadoutOptions = $this->buildLoadoutOptions(
			$options->get( 'CreateWikiLoadoutEnabled' ),
			$options->get( 'CreateWikiLoadoutConfigs' )
		);
	}

	public function isEnabled(): bool {
		return !empty( $this->loadoutOptions );
	}

	public function getFormDescriptor(): array {
		return [
			'type' => 'select',
			'label-message' => 'cwloadout-label-loadout',
			'help-message' => 'cwloadout-help-loadout',
			'options' => $this->loadoutOptions,
			'default' => '',
		];
	}

	private function buildLoadoutOptions( bool $enableLoadoutSelector, ?array $loadouts ): array {
		if ( !$enableLoadoutSelector || !$loadouts ) {
			return [];
		}
		$options = [ wfMessage( 'cwloadout-label-loadout-none' )->inContentLanguage()->text() => '' ];
		foreach ( $loadouts as $loadoutKey => $loadout ) {
			$options[ wfMessage( "cwloadout-label-loadout-$loadoutKey" )->inContentLanguage()->text() ] = $loadoutKey;
		}
		return $options;
	}
}
