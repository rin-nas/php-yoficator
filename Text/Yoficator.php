<?php
/**
 * Yoficator
 * Ёфикатор
 *
 * Purpose
 *   Корректировка существующих и новых текстов, публикуемых посетителями на веб-сайтах.
 *
 * Features
 *   * Восстанавление буквы «ё» в русских текстах, в которых вместо неё употреблена буква «е».
 *   * Заменяет «е» на «ё» только в бесспорных случаях («неполная» или «быстрая» ёфикация).
 *   * Исправление нескольких букв е, ё в словах.
 *   * Корректная обработка сокращений, пример: «мед. училище» (но не «мёд. училище»).
 *   * Аббревиатуры не обрабатываются (пример: ОПЕК).
 *   * Кодировка символов — UTF-8.
 *   * Класс может работать без PHP-расширений mbstring и iconv!
 *
 * WARNING
 *   При первом запуске программа конвертирует словарь Yoficator.dic.dat в нужный ей формат
 *   и сохраняет файлы Yoficator.dic.php и Yoficator.dic.cdb в ту же папку, где находится класс.
 *
 * Useful links
 *   http://ru.wikipedia.org/wiki/Ёфикатор
 *   http://www.yomaker.ru/       — cайт «главного ёфикатора России» Виктора Трофимовича Чумакова.
 *   http://vgiv.narod.ru/yo.html — Ёфикатор Владимира Иванова.
 *
 * ОМОНИМИЯ С БУКВАМИ Е/Ё
 *   передохнём (отдохнём) != передохнем (умрём)
 *   лён (ткань)           != лен (феодальное владение)
 *   съём (жилья)          != съем (скушаю)
 *   всё (everything)      != все (everyone)
 *   Лёне (Голубкову)      != Лене (Голубковой)
 *
 * ОТЛИЧАТЬ БУКВЫ Е/Ё — ВАЖНО:
 *   День за днём горим огнём,
 *   Не вздохнём, не охнем,
 *   Если не передохнём,
 *   Значит, передохнем…
 *        /Валентин Берестов/
 * 
 * Почему же, ё-моё, ты нигде не пишешь «ё»? :)
 *
 * @link     http://code.google.com/p/php-yoficator/
 * @license  http://creativecommons.org/licenses/by-sa/3.0/
 * @author   Nasibullin Rinat
 * @version  1.0.7
 */
class Text_Yoficator
{
	private $dic;   #dictionary
	private $db;    #resource of CDB
	private $words; #corrected words
	private $is_work_for_cdb_only;
	private $is_hash;

	private $e_uc = "\xd0\x95"; #Е
	private $e_lc = "\xd0\xb5"; #е

	private $yo_uc = "\xd0\x81"; #Ё
	private $yo_lc = "\xd1\x91"; #ё

	private $ru    = '\xd0[\x90-\xbf\x81]|\xd1[\x80-\x8f\x91]'; #А-я (all)
	private $ru_uc = '\xd0[\x90-\xaf\x81]';                     #А-Я (uppercase)
	private $ru_lc = '\xd0[\xb0-\xbf]|\xd1[\x80-\x8f\x91]';     #а-я (lowercase)

	/**
	 * @param  bool  $is_work_for_cdb_only  Класс будет функционировать, только если в PHP есть поддержка CDB.
	 *                                      Для небольших обрабатываемых данных (экспериментально до 50 КБ)
	 *                                      использование CDB-словаря обычно выгоднее, чем PHP-словаря, т. к.
	 *                                      не тратится время загрузку словаря. Но работать с PHP-словарём
	 *                                      немного быстрее, чем CDB, т. к. он находится целиком в оперативной памяти.
	 * @param  bool  $is_hash               Хэшировать ключи словарей для уменьшения их размера?
	 *                                      В случае обнаружения проблем отключите хэширование и удалите файлы
	 *                                      словарей Yoficator.dic.cdb и Yoficator.dic.php, они пересоздадутся
	 *                                      заново при первом запуске. Не забудьте сообщить о проблеме
	 *                                      разработчику этого класса!
	 */
	public function __construct($is_work_for_cdb_only = false, $is_hash = true)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		$this->is_work_for_cdb_only = $is_work_for_cdb_only;
		$this->is_hash = $is_hash;
		$this->_dat2php();
		$this->_php2cdb();
	}

	/**
	 * Главный метод
	 *
	 * @param   scalar|null $s      Текст в кодировке UTF-8.
	 * @param   array|null  $words  Ассоц. массив со словами, которые были исправлены:
	 *                              в ключах оригиналы, в значениях исправленные слова.
	 * @return  string|bool         returns FALSE if error occured
	 */
	public function parse($s, array &$words = null)
	{
		if (! ReflectionTypeHint::isValid()) return false;
		if (! is_string($s)) return $s;

		#пропускаем текст, в котором нет букв [ЕеЁё]
		if ($this->_is_skip($s)) return $s;  #speed improve

		if ( ! (is_array($this->dic) || is_resource($this->db)) )
		{
			if ( function_exists('dba_open') &&
				array_key_exists('cdb', dba_handlers(true))
			)
			{
				$this->db = dba_open($this->_filename('cdb'), 'r', 'cdb');
				if ($this->db === false) return $s;
			}
			elseif ($this->is_work_for_cdb_only) return $s;
			else include $this->_filename('php');
		}

		#вырезаем и заменяем некоторые символы
		$additional_chars = array(
			"\xc2\xad",  #"мягкие" переносы строк (&shy;)
		);
		$s = UTF8::diactrical_remove($s, $additional_chars, $is_can_restored = true, $restore_table);

		$this->words = array();
		#заменяем слова из текста, минимальная длина слова -- 3 буквы, меньше нельзя
		$s = preg_replace_callback('/ (' . $this->ru . ')               #1 первая буква
                                      ((?:' . $this->ru_lc . '){2,}+)   #2 остальные буквы
                                      (?!
                                         \.(?>[\x00-\x20]+|\xc2\xa0)+  #\xc2\xa0 = &nbsp;
                                           (?>
                                               (?:' . $this->ru_lc . ')
                                             | (?:' . $this->ru_uc . '){2}   #пример: долл. США
                                             | [\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]
                                           )
                                       | \.[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]
                                      )
                                    /sxSX', array(&$this, '_word'), $s);
		$s = UTF8::diactrical_restore($s, $restore_table);
		$words = $this->words;
		return $s;
	}

	#пропускаем текст/слова, в которых нет букв [еЕёЁ]
	private function _is_skip($s)
	{
		#return ! preg_match('/(?:\xd0[\x95\xb5\x81]|\xd1\x91)/sSX', $word));
		#через strpos() работает быстрее!
		return (strpos($s, $this->e_lc)  === false &&
				strpos($s, $this->e_uc)  === false &&
				strpos($s, $this->yo_lc) === false &&
				strpos($s, $this->yo_uc) === false);
	}

	private function _word(array &$a)
	{
		$word = $a[0];
		#пропускаем слова, в которых нет букв [еЕёЁ]
		if ($this->_is_skip($word)) return $word;

		$is_first_letter_uc = array_key_exists($a[1], UTF8::$convert_case_table);
		$s = $is_first_letter_uc ? UTF8::$convert_case_table[$a[1]] . $a[2] : $word; #fist letter to lowercase
		$s = str_replace($this->yo_lc, $this->e_lc, $s); #ё => е
		$hash = $this->_hash($s);

		if (! is_array($this->dic))
		{
			$pos = dba_fetch($hash, $this->db);
			if ($pos === false) return $word;
		}
		elseif (array_key_exists($hash, $this->dic)) $pos = $this->dic[$hash];
		else return $word;

		foreach (explode(',', $pos) as $p)
		{
			$replacement = ($p == 0 && $is_first_letter_uc) ? $this->yo_uc : $this->yo_lc;
			$word2 = substr_replace($word, $replacement, $p * 2, 2);
		}

		#предотвращаем возможные коллизии в хэшах, надёжность прежде всего
		if ($this->is_hash
			&& str_replace(array($this->yo_uc, $this->yo_lc), array($this->e_uc, $this->e_lc), $word2) !== $word) return $word;

		return $this->words[$word] = $word2;
	}

	/**
	 * Метод, который конвертирует словарь Владимира Иванова (см. http://vgiv.narod.ru/yo.html) в PHP-словарь
	 * Yoficator.dic.dat (cp1251) => Yoficator.dic.php (UTF-8)
	 * При конвертации комментарии (символ "#" в начале строки), а так же слова, после которых стоит "?" или "*" игнорируются.
	 *
	 * @return  int  кол-во слов или FALSE в случае ошибки
	 */
	private function _dat2php()
	{
		if (file_exists($this->_filename('php'))) return false;
		$s = file_get_contents($this->_filename('dat'));
		if ($s === false) return false;
		$s = UTF8::convert_from($s, 'cp1251');
		$rows = explode("\r\n", $s);
		unset($s); #экономим память
		$words = array();
		foreach ($rows as $i => &$row)
		{
			$row = trim($row);
			if (substr($row, 0, 1) === '#' || substr($row, -1, 1) === '?' || substr($row, -1, 1) === '*')
			{
				unset($rows[$i]);
				continue;
			}
			$p = array();
			$count = 0;
			$a = explode($this->yo_lc, $row);
			if (array_pop($a) === null)
			{
				#ошибка в словаре: в слове нет буквы "ё"...
				unset($rows[$i]);
				continue;
			}
			foreach ($a as $v)
			{
				$p[] = strlen($v) / 2 + $count;
				$count += 2;
			}
			$word = str_replace($this->yo_lc, $this->e_lc, $row);
			$hash = $this->_hash($word);
			if (array_key_exists($hash, $words)) trigger_error('Hash collision found, improve _hash() function!', E_USER_ERROR);
			$value = implode(',', $p);
			$words[$hash] = count($a) > 1 ? $value : intval($value);
			unset($rows[$i]); #экономим память
		}
		ksort($words); #for best dictionaries compression! :)
		$s = "<?php\r\n#autogenerated by " . __CLASS__ . ' PHP class at ' . date('Y-m-d H:i:s') . ', ' . count($words) . " wordforms total\r\n#charset UTF-8" . ($this->is_hash ? ' (hashed)' : '') . "\r\n\$this->dic = " . var_export($words, true) . ";\r\n?>";
		if (file_put_contents($this->_filename('php'), $s) === false) return false;
		$this->dic =& $words;
		return count($words);
	}

	/**
	 * Создает из PHP-словаря словарь в формате CDB (файл с расширением .cdb в той же директории).
	 * Yoficator.dic.php (UTF-8) => Yoficator.dic.cdb (UTF-8)
	 *
	 * @link    http://ru2.php.net/dba
	 * @return  bool   TRUE, если словарь создан, FALSE в противном случае (CDB не поддерживается или файл уже существует).
	 */
	private function _php2cdb()
	{
		if (! function_exists('dba_open') || ! array_key_exists('cdb_make', dba_handlers(true))) return false;
		$filename = $this->_filename('cdb');
		if (file_exists($filename)) return false;
		if (! is_array($this->dic)) include $this->_filename('php');
		$db = dba_open($filename, 'n', 'cdb_make');
		if ($db === false) return false; #нет права доступа на запись в папку
		foreach ($this->dic as $k => $v) dba_insert($k, $v, $db);
		dba_optimize($db);
		dba_close($db);
	}

	#возвращает имя файла словаря
	private function _filename($ext = 'php')
	{
		$a = explode('_', __CLASS__);
		$name = end($a);
        return __DIR__ . '/' . $name . '.dic.' . $ext;
	}

	private function _hash(/*string*/ $s, $length = 4)
	{
		if (! $this->is_hash) return $s;
		#return pack('l', crc32($s)); #DEPRECATED, hash collisions found
		return substr(md5($s, $raw_output = true), 0, $length);
	}
}