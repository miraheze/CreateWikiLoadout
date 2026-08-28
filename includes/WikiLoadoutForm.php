<?php

namespace MediaWiki\Extension\CreateWikiLoadout;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Html\Html;

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
			'help-raw' => $this->buildHelpText(),
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

	private function buildHelpText(): string {
		$items = '';
		foreach ( $this->loadoutOptions as $label => $loadoutKey ) {
			$messageKey = 'cwloadout-help-loadout-' . ( $loadoutKey === '' ? 'none' : $loadoutKey );
			$description = wfMessage( $messageKey )->inContentLanguage();
			// Format: "label: description"
			$content = [
				Html::element( 'strong', [], (string)$label ),
				wfMessage( 'colon-separator' )->inContentLanguage()->escaped(),
				$description->parse(),
			];
			$items .= Html::rawElement( 'li', [], implode( "", $content ) );
		}
		$intro = wfMessage( 'cwloadout-help-loadout' )->inContentLanguage()->parse();
		return $intro . Html::rawElement( 'ul', [ 'class' => 'cwloadout-help-loadout-list' ], $items );
	}
}
