# CreateWikiLoadout

The CreateWikiLoadout extension adds loadout functionality to the CreateWiki extension, allowing wikis to be created with pre-configured extensions, settings, and pre-populated content from XML dumps.

## Configuration

### CreateWikiLoadoutEnabled

Boolean. Whether to enable the loadout selector in wiki creation forms.

```php
$wgCreateWikiLoadoutEnabled = true;
```

Default: `false`

### CreateWikiLoadoutConfigs

Array. Associative array mapping loadout names to configuration arrays. Each loadout config can have: 'xml' (path to XML dump), 'extensions' (array of extensions to enable via ManageWiki), 'settings' (array of settings for ManageWiki).

```php
$wgCreateWikiLoadoutConfigs = [
	'default' => [
		'xml' => '/path/to/default_dump.xml',
		'extensions' => [ 'gadgets' ],
		'settings' => [ ],
	],
	'fandom' => [
		'extensions' => [ 'gadgets', 'dynamicpagelist4', 'dummyfandoommainpagetags', 'templatedata', 'loopscombo', 'variables', 'tabs', 'tabberneue', 'userprofilev2', 'visualeditor', 'linter', 'discussiontools', 'nukedpl' ],
		'settings' => [ 'wgUseQuickInstantCommons' => false, 'wgMirahezeCommons' => false, 'wgPFEnableStringFunctions' => true, 'wgRestrictDisplayTitle' => false ],
	],
];
```

Default: `[]`

## TODO

Make an extra field on Special:CreateWiki as well, which requires an extra hook in CreateWiki.

Disable main page creation when a non-default loadout is chosen.
