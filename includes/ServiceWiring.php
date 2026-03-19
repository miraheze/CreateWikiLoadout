<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\CreateWikiLoadout\WikiLoadoutForm;

return [
	'CreateWikiLoadout.WikiLoadoutForm' => static function ( MediaWikiServices $services ): WikiLoadoutForm {
		return new WikiLoadoutForm( $services->getMainConfig() );
	},
];
