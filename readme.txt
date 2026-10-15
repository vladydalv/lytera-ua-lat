=== Lytera UA LAT ===
Contributors: wpspacenerd
Donate link: https://www.spacenerd.space/
Tags: ukr-to-lat, ukr2lat, ukrainian, transliteration, slugs
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ukr to Lat: transliterates Ukrainian slugs and file names to Latin by the official standard, with 301 redirects from old URLs.

== Description ==

Ukr to Lat transliteration for WordPress. Turns Ukrainian slugs into readable Latin URLs according to the official standard (Resolution of the Cabinet of Ministers of Ukraine No. 55, 2010).

= Features =
* Post, page, custom post type and term slugs
* Attachment file names
* Tools → Lytera UA LAT: preview which slugs will change and convert existing slugs in batches
* 301 redirects from old post and term URLs (also after manual term slug edits)
* Not-yet-converted Cyrillic URLs keep working
* WooCommerce: attributes and variations with Ukrainian names keep working (existing data is migrated automatically)
* Word-initial rules: Є, Ї, Й, Ю, Я → Ye, Yi, Y, Yu, Ya; inside a word → ie, i, i, iu, ia
* "зг" → "zgh"; soft sign and apostrophe are dropped
* Ukrainian only

Example: `Згорани Юрій Їжакевич` → `zghorany-yurii-yizhakevych`.

== Frequently Asked Questions ==

= How can I change the table? =

`
add_filter( 'uatr_table', function ( $table ) {
	$table['щ'] = 'sch';
	return $table;
} );
`

Word-initial letters use the `uatr_initial_table` filter.

= Will existing URLs break after activation? =

No. Existing slugs change only when you click Convert all in Tools → Lytera UA LAT. After conversion old URLs redirect to the new ones.

== Changelog ==

= 1.0.0 =
* Initial release.
