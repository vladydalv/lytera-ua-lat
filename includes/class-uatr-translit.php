<?php
/**
 * Transliteration class.
 *
 * @package Lytera_UA_LAT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transliteration (Resolution of the Cabinet of Ministers of Ukraine No. 55, 27.01.2010).
 */
final class UATR_Translit {

	/**
	 * Letters inside a word.
	 *
	 * @return array<string,string>
	 */
	private static function table() {
		$table = array(
			'а' => 'a',
			'б' => 'b',
			'в' => 'v',
			'г' => 'h',
			'ґ' => 'g',
			'д' => 'd',
			'е' => 'e',
			'є' => 'ie',
			'ж' => 'zh',
			'з' => 'z',
			'и' => 'y',
			'і' => 'i',
			'ї' => 'i',
			'й' => 'i',
			'к' => 'k',
			'л' => 'l',
			'м' => 'm',
			'н' => 'n',
			'о' => 'o',
			'п' => 'p',
			'р' => 'r',
			'с' => 's',
			'т' => 't',
			'у' => 'u',
			'ф' => 'f',
			'х' => 'kh',
			'ц' => 'ts',
			'ч' => 'ch',
			'ш' => 'sh',
			'щ' => 'shch',
			'ь' => '',
			'ю' => 'iu',
			'я' => 'ia',
		);
		/**
		 * Filters the transliteration table (lowercase keys).
		 *
		 * @param array $table Letter => Latin.
		 */
		return apply_filters( 'uatr_table', $table );
	}

	/**
	 * Letters at the beginning of a word.
	 *
	 * @return array<string,string>
	 */
	private static function initial_table() {
		$table = array(
			'є' => 'ye',
			'ї' => 'yi',
			'й' => 'y',
			'ю' => 'yu',
			'я' => 'ya',
		);
		/**
		 * Filters word-initial transliteration (lowercase keys).
		 *
		 * @param array $table Letter => Latin.
		 */
		return apply_filters( 'uatr_initial_table', $table );
	}

	/**
	 * Whether the string contains Cyrillic.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_cyrillic( $text ) {
		return is_string( $text ) && 1 === preg_match( '/[\x{0400}-\x{04FF}]/u', $text );
	}

	/**
	 * Transliterate a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function transliterate( $text ) {
		if ( ! self::has_cyrillic( $text ) ) {
			return $text;
		}

		static $upper = array(
			'А' => 'а',
			'Б' => 'б',
			'В' => 'в',
			'Г' => 'г',
			'Ґ' => 'ґ',
			'Д' => 'д',
			'Е' => 'е',
			'Є' => 'є',
			'Ж' => 'ж',
			'З' => 'з',
			'И' => 'и',
			'І' => 'і',
			'Ї' => 'ї',
			'Й' => 'й',
			'К' => 'к',
			'Л' => 'л',
			'М' => 'м',
			'Н' => 'н',
			'О' => 'о',
			'П' => 'п',
			'Р' => 'р',
			'С' => 'с',
			'Т' => 'т',
			'У' => 'у',
			'Ф' => 'ф',
			'Х' => 'х',
			'Ц' => 'ц',
			'Ч' => 'ч',
			'Ш' => 'ш',
			'Щ' => 'щ',
			'Ь' => 'ь',
			'Ю' => 'ю',
			'Я' => 'я',
		);

		$table   = self::table();
		$initial = self::initial_table();

		// Apostrophe is not transliterated.
		$text    = str_replace( array( "'", '’', 'ʼ', '`', '‘' ), '', $text );
		$chars   = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$count   = count( $chars );
		$out     = '';
		$in_word = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$char     = $chars[ $i ];
			$is_upper = isset( $upper[ $char ] );
			$lower    = $is_upper ? $upper[ $char ] : $char;

			if ( ! isset( $table[ $lower ] ) ) {
				$out    .= $char;
				$in_word = 1 === preg_match( '/^\p{L}$/u', $char );
				continue;
			}

			$next = $chars[ $i + 1 ] ?? '';
			$next = $upper[ $next ] ?? $next;

			if ( 'з' === $lower && 'г' === $next ) {
				$latin = 'zgh';
				++$i;
			} elseif ( ! $in_word && isset( $initial[ $lower ] ) ) {
				$latin = $initial[ $lower ];
			} else {
				$latin = $table[ $lower ];
			}

			$out    .= $is_upper ? ucfirst( $latin ) : $latin;
			$in_word = true;
		}

		return $out;
	}
}
