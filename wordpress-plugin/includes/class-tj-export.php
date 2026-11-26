<?php if (!defined('ABSPATH')) exit;
/**
 * TicketJunkies report exporters: PDF, Word (.docx), Excel (.xlsx), CSV and the ticket PDF.
 * Port of exporters.mjs — no external dependencies (no ZipArchive, no GD).
 *
 * Document model (associative arrays):
 *   [ 'title' => ..., 'subtitle' => ..., 'meta' => [[label, value], ...],
 *     'sections' => [[ 'title' => ..., 'sheet' => ?, 'columns' => [['label'=>, 'width'=>, 'type'=>], ...], 'rows' => [[...]], 'totals' => ? ]] ]
 * Column types: 'text' | 'money' | 'int' | 'date'. Dates are ISO-8601 strings (or ms timestamps / DateTimeInterface).
 */
final class TJ_Export {
	const MONEY_KEYS = array('Ticket sales', 'Platform fees', 'Service fees (buyers)', 'Total collected', 'Avg order', 'Refunded amount');

	// ============================================================ JS-compatible scalar helpers
	private static function is_num($v): bool { return is_int($v) || is_float($v); }

	/** JS Number(v) */
	private static function js_number($v) {
		if (is_int($v) || is_float($v)) return $v;
		if ($v === null || $v === false || $v === '') return 0;
		if ($v === true) return 1;
		if (is_string($v)) {
			$t = trim($v);
			if ($t === '') return 0;
			if (is_numeric($t)) return $t + 0;
			if (preg_match('/^0x[0-9a-f]+$/i', $t)) return hexdec($t);
			return NAN;
		}
		return NAN;
	}

	/** JS Number.prototype.toString() for a PHP number */
	public static function js_num($v): string {
		if (is_int($v)) return (string) $v;
		if (!is_float($v)) $v = (float) self::js_number($v);
		if (is_nan($v)) return 'NaN';
		if (is_infinite($v)) return $v > 0 ? 'Infinity' : '-Infinity';
		if ($v == 0) return '0';
		$neg = $v < 0; $a = abs($v);
		$s = '';
		for ($p = 1; $p <= 17; $p++) { $s = sprintf('%.' . ($p - 1) . 'e', $a); if ((float) $s === $a) break; }
		list($mant, $exp) = explode('e', $s);
		$digits = rtrim(str_replace('.', '', $mant), '0');
		if ($digits === '') $digits = '0';
		$k = strlen($digits); $n = (int) $exp + 1;
		if ($k <= $n && $n <= 21) $out = $digits . str_repeat('0', $n - $k);
		elseif (0 < $n && $n <= 21) $out = substr($digits, 0, $n) . '.' . substr($digits, $n);
		elseif (-6 < $n && $n <= 0) $out = '0.' . str_repeat('0', -$n) . $digits;
		else $out = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($n - 1 >= 0 ? '+' : '-') . abs($n - 1);
		return ($neg ? '-' : '') . $out;
	}

	/** JS Number.prototype.toFixed(d) (round-half-up on exact binary ties, like V8) */
	public static function to_fixed($v, int $d): string {
		$v = self::js_number($v);
		if (is_float($v) && is_nan($v)) return 'NaN';
		$v = (float) $v;
		if ($v == 0) return sprintf('%.' . $d . 'f', 0);
		if (abs($v) >= 1e21) return self::js_num($v);
		$s = sprintf('%.' . $d . 'f', $v);
		$x = abs($v) * pow(10, $d);
		if ($x - floor($x) == 0.5 && fmod(floor($x), 2) == 0) { // exact tie that sprintf rounded to even (down)
			$r = (floor($x) + 1) / pow(10, $d);
			$s = ($v < 0 ? '-' : '') . sprintf('%.' . $d . 'f', $r);
		}
		return $s;
	}

	/** JS String(v) (null -> $null_as) */
	private static function jstr($v, string $null_as = ''): string {
		if ($v === null) return $null_as;
		if ($v === true) return 'true';
		if ($v === false) return 'false';
		if (is_float($v)) return self::js_num($v);
		if (is_array($v)) return implode(',', array_map(function ($x) { return self::jstr($x, ''); }, $v));
		if ($v instanceof DateTimeInterface) return $v->format(DATE_RFC2822);
		return (string) $v;
	}

	/** JS truthiness */
	private static function truthy($v): bool {
		if (is_float($v) && is_nan($v)) return false;
		if (is_array($v) || is_object($v)) return true;
		return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0;
	}

	private static function utf16_len(string $s): int {
		if (function_exists('mb_strlen')) return (int) (strlen(@mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')) / 2);
		return strlen($s);
	}

	/** JS String.prototype.slice(start, end) on UTF-16 code units (never splits a surrogate pair) */
	private static function js_slice(string $s, int $start, ?int $end = null): string {
		$chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) $chars = str_split($s);
		$units = array(); // each char with its utf16 width
		$total = 0;
		foreach ($chars as $c) { $w = strlen($c) === 4 ? 2 : 1; $units[] = array($c, $w); $total += $w; }
		if ($start < 0) $start = max(0, $total + $start);
		if ($end === null) $end = $total; elseif ($end < 0) $end = max(0, $total + $end);
		$out = ''; $pos = 0;
		foreach ($units as $u) {
			if ($pos >= $start && $pos + $u[1] <= $end) $out .= $u[0];
			$pos += $u[1];
		}
		return $out;
	}

	private static function upper(string $s): string { return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s); }
	private static function lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }

	private static function js_trim(string $s): string {
		$ws = '[\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
		$r = preg_replace('/^' . $ws . '+|' . $ws . '+$/u', '', $s);
		return $r === null ? trim($s) : $r;
	}

	// ============================================================ dates
	private static function tz(): DateTimeZone {
		if (function_exists('wp_timezone')) { $z = wp_timezone(); if ($z instanceof DateTimeZone) return $z; }
		return new DateTimeZone('UTC');
	}

	/** Parse like JS new Date(v); returns DateTimeImmutable in site tz, or null when invalid. */
	private static function to_date($v): ?DateTimeImmutable {
		$tz = self::tz();
		try {
			if ($v instanceof DateTimeInterface) return (new DateTimeImmutable('@' . $v->getTimestamp()))->setTimezone($tz);
			if (is_int($v) || is_float($v)) {
				if (!is_finite((float) $v)) return null;
				$sec = (int) floor($v / 1000);
				return (new DateTimeImmutable('@' . $sec))->setTimezone($tz);
			}
			$s = trim((string) $v);
			if ($s === '') return null;
			if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $s)) { // date-only ISO forms are UTC in JS
				$d = new DateTimeImmutable($s . (strlen($s) === 4 ? '-01-01' : (strlen($s) === 7 ? '-01' : '')), new DateTimeZone('UTC'));
				return $d->setTimezone($tz);
			}
			$d = new DateTimeImmutable($s, $tz);
			return $d->setTimezone($tz);
		} catch (Exception $e) {
			return null;
		}
	}

	private static function fmt_date($v): string {
		if (!self::truthy($v)) return '';
		$d = self::to_date($v);
		return $d ? $d->format('M j, Y, g:i A') : 'Invalid Date';
	}

	private static function fmt_when($v): string {
		if (!self::truthy($v)) return '';
		$d = self::to_date($v);
		return $d ? $d->format('l, F j, Y \a\t g:i A') : 'Invalid Date';
	}

	private static function excel_date($v) {
		$d = self::to_date($v);
		if (!$d) return null;
		// local wall-clock components -> UTC epoch (like Date.UTC(getFullYear(), ...)), seconds precision
		$u = gmmktime((int) $d->format('G'), (int) $d->format('i'), (int) $d->format('s'), (int) $d->format('n'), (int) $d->format('j'), (int) $d->format('Y'));
		return ($u / 86400) + 25569;
	}

	private static function now_local(): DateTimeImmutable {
		return (new DateTimeImmutable('now'))->setTimezone(self::tz());
	}

	// ============================================================ formatting helpers
	private static function xml($s): string {
		$s = self::jstr($s, '');
		$s = str_replace(array('&', '<', '>', '"'), array('&amp;', '&lt;', '&gt;', '&quot;'), $s);
		return preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s);
	}

	private static function fmt_money($n): string {
		if ($n === null || $n === '') return '';
		$num = self::js_number($n);
		if (is_float($num) && is_nan($num)) return '$NaN';
		$neg = $n < 0 || $num < 0;
		$fixed = self::intl_round2(abs((float) $num));
		list($int, $dec) = explode('.', $fixed);
		$int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);
		return ($neg ? '-$' : '$') . $int . '.' . $dec;
	}

	/** Like Intl.NumberFormat (maximumFractionDigits 2): round the shortest decimal representation half-up. */
	private static function intl_round2(float $a): string {
		$s = self::js_num($a);
		if (strpos($s, 'e') !== false || $s === 'Infinity' || $s === 'NaN') return self::to_fixed($a, 2);
		$parts = explode('.', $s);
		$int = $parts[0]; $frac = isset($parts[1]) ? $parts[1] : '';
		$frac3 = str_pad($frac, 3, '0');
		$cents = $int . substr($frac3, 0, 2);
		if ((int) $frac3[2] >= 5) { // add one to the decimal string
			$d = str_split($cents); $i = count($d) - 1;
			while ($i >= 0) { if ($d[$i] === '9') { $d[$i] = '0'; $i--; } else { $d[$i] = (string) ((int) $d[$i] + 1); break; } }
			$cents = ($i < 0 ? '1' : '') . implode('', $d);
		}
		$cents = ltrim($cents, '0');
		$cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
		return substr($cents, 0, -2) . '.' . substr($cents, -2);
	}

	public static function cell_text($v, $type): string {
		if ($type === 'money') return self::fmt_money($v);
		if ($type === 'date') return self::fmt_date($v);
		if ($type === 'int') return $v === null ? '' : self::jstr($v);
		return self::jstr($v, '');
	}

	private static function is_money_key($k): bool { return in_array($k, self::MONEY_KEYS, true); }

	private static function col_type(array $cols, int $i) {
		return isset($cols[$i]['type']) ? $cols[$i]['type'] : null;
	}

	private static function col_width(array $c) {
		return (isset($c['width']) && self::truthy($c['width'])) ? $c['width'] : 12;
	}

	private static function meta(array $model): array { return isset($model['meta']) && is_array($model['meta']) ? $model['meta'] : array(); }
	private static function sections(array $model): array { return isset($model['sections']) && is_array($model['sections']) ? $model['sections'] : array(); }
	private static function g(array $a, string $k, $def = null) { return array_key_exists($k, $a) ? $a[$k] : $def; }

	// ============================================================ ZIP (for xlsx / docx)
	public static function zip(array $files): string {
		$now = self::now_local();
		$dos_time = ((int) $now->format('G') << 11) | ((int) $now->format('i') << 5) | ((int) $now->format('s') >> 1);
		$dos_date = (((int) $now->format('Y') - 1980) << 9) | ((int) $now->format('n') << 5) | (int) $now->format('j');
		$locals = ''; $centrals = ''; $offset = 0;
		foreach ($files as $f) {
			$name = (string) $f['name'];
			$data = (string) $f['data'];
			$comp = gzdeflate($data, 9);
			$crc = crc32($data);
			$lh = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $dos_time, $dos_date, $crc, strlen($comp), strlen($data), strlen($name), 0);
			$locals .= $lh . $name . $comp;
			$ch = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $dos_time, $dos_date, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset);
			$centrals .= $ch . $name;
			$offset += strlen($lh) + strlen($name) + strlen($comp);
		}
		$end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($centrals), $offset, 0);
		return $locals . $centrals . $end;
	}

	// ============================================================ CSV
	public static function csv(array $model): string {
		$q = function ($x) {
			$s = self::jstr($x, '');
			$safe = preg_match('/^[=+\-@]/', $s) ? "'" . $s : $s;
			return (preg_match('/[",\r\n]/', $safe) || $safe !== $s) ? '"' . str_replace('"', '""', $safe) . '"' : $safe;
		};
		$out = array(array(self::g($model, 'title')), array(self::g($model, 'subtitle')), array());
		foreach (self::meta($model) as $kv) {
			$k = isset($kv[0]) ? $kv[0] : null; $v = isset($kv[1]) ? $kv[1] : null;
			$out[] = array($k, self::is_money_key($k) ? self::to_fixed($v, 2) : $v);
		}
		foreach (self::sections($model) as $s) {
			$cols = self::g($s, 'columns', array());
			$out[] = array();
			$out[] = array(self::g($s, 'title'));
			$out[] = array_map(function ($c) { return self::g($c, 'label'); }, $cols);
			foreach (self::g($s, 'rows', array()) as $r) {
				$line = array();
				foreach (array_values($r) as $i => $v) {
					$t = self::col_type($cols, $i);
					$line[] = $t === 'money' ? (($v === '' || $v === null) ? '' : self::to_fixed($v, 2)) : self::cell_text($v, $t);
				}
				$out[] = $line;
			}
			$totals = self::g($s, 'totals');
			if (self::truthy($totals)) {
				$line = array();
				foreach (array_values($totals) as $i => $v) {
					$line[] = (self::col_type($cols, $i) === 'money' && $v !== '') ? self::to_fixed($v, 2) : ($v === null ? '' : $v);
				}
				$out[] = $line;
			}
		}
		return "\xEF\xBB\xBF" . implode("\r\n", array_map(function ($r) use ($q) { return implode(',', array_map($q, $r)); }, $out));
	}

	// ============================================================ XLSX
	private static function col_name(int $i): string {
		$s = ''; $i++;
		while ($i) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
		return $s;
	}

	public static function xlsx(array $model): string {
		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="mmm d, yyyy h:mm AM/PM"/></numFmts>
<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="18"/><color rgb="FF376EB8"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>
<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF376EB8"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F7"/><bgColor indexed="64"/></patternFill></fill></fills>
<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top style="thin"><color rgb="FF9CA3AF"/></top><bottom/><diagonal/></border></borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="11">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>
<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="164" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>
<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>
<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>
<xf numFmtId="1" fontId="2" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>
<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>
</cellXfs>
<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>';

		$sheets = array();
		$used = array();
		$sheet_name = function ($t) use (&$used) {
			$n = self::jstr($t, '');
			$n = preg_replace('/[\[\]:*?\/\\\\]/u', '', $n);
			$n = preg_replace('/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', '', $n);
			$n = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $n);
			$n = self::js_slice(self::js_trim((string) $n), 0, 31);
			if ($n === '') $n = 'Sheet';
			$k = 2; $base = $n;
			while (isset($used[self::lower($n)])) $n = self::js_slice($base, 0, 28) . ' ' . $k++;
			$used[self::lower($n)] = true;
			return $n;
		};
		$str = function ($ref, $v, $s = 0) { return '<c r="' . $ref . '" t="inlineStr" s="' . $s . '"><is><t xml:space="preserve">' . self::xml($v) . '</t></is></c>'; };
		$num = function ($ref, $v, $s) { return '<c r="' . $ref . '" s="' . $s . '"><v>' . self::js_num(self::js_number($v)) . '</v></c>'; };

		$title = self::g($model, 'title'); $subtitle = self::g($model, 'subtitle');
		// Summary sheet
		$rows = array('<row r="1">' . $str('A1', $title, 5) . '</row>', '<row r="2">' . $str('A2', $subtitle, 6) . '</row>');
		$r = 4;
		foreach (self::meta($model) as $kv) {
			$k = isset($kv[0]) ? $kv[0] : null; $v = isset($kv[1]) ? $kv[1] : null;
			$is_money = self::is_num($v) && self::is_money_key($k);
			$rows[] = '<row r="' . $r . '">' . $str('A' . $r, $k, 10) . (self::is_num($v) ? $num('B' . $r, $v, $is_money ? 2 : 8) : $str('B' . $r, $v)) . '</row>';
			$r++;
		}
		$sheets[] = array('name' => $sheet_name('Summary'), 'xml' => '<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="48" customWidth="1"/></cols><sheetData>' . implode('', $rows) . '</sheetData>');

		foreach (self::sections($model) as $sec) {
			$cols = array_values(self::g($sec, 'columns', array()));
			$sec_rows = self::g($sec, 'rows', array());
			$head = 4;
			$rows = array('<row r="1">' . $str('A1', self::g($sec, 'title'), 5) . '</row>', '<row r="2">' . $str('A2', $subtitle, 6) . '</row>');
			$h = '';
			foreach ($cols as $i => $c) $h .= $str(self::col_name($i) . $head, self::g($c, 'label'), 1);
			$rows[] = '<row r="' . $head . '" ht="20" customHeight="1">' . $h . '</row>';
			$r = $head + 1;
			foreach ($sec_rows as $row) {
				$cells = '';
				foreach (array_values($row) as $i => $v) {
					$ref = self::col_name($i) . $r; $t = self::col_type($cols, $i);
					if ($v === '' || $v === null) continue;
					if ($t === 'money') $cells .= $num($ref, $v, 2);
					elseif ($t === 'int') $cells .= $num($ref, $v, 8);
					elseif ($t === 'date') { $d = self::excel_date($v); $cells .= $d === null ? $str($ref, $v) : $num($ref, $d, 7); }
					else $cells .= $str($ref, $v);
				}
				$rows[] = '<row r="' . $r . '">' . $cells . '</row>';
				$r++;
			}
			$totals = self::g($sec, 'totals');
			if (self::truthy($totals)) {
				$cells = '';
				foreach (array_values($totals) as $i => $v) {
					$ref = self::col_name($i) . $r; $t = self::col_type($cols, $i);
					if ($v === '' || $v === null) $cells .= '<c r="' . $ref . '" s="3"/>';
					elseif ($t === 'money') $cells .= $num($ref, $v, 4);
					elseif ($t === 'int' && self::is_num($v)) $cells .= $num($ref, $v, 9);
					else $cells .= $str($ref, $v, 3);
				}
				$rows[] = '<row r="' . $r . '">' . $cells . '</row>';
			}
			$last = self::col_name(count($cols) - 1);
			$last_data = max($head, $head + count($sec_rows));
			$colxml = '';
			foreach ($cols as $i => $c) {
				$w = max(8, min(60, self::js_number(self::col_width($c)) * 1.15));
				$colxml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . self::to_fixed($w, 1) . '" customWidth="1"/>';
			}
			$name_src = self::truthy(self::g($sec, 'sheet')) ? self::g($sec, 'sheet') : self::g($sec, 'title');
			$sheets[] = array(
				'name' => $sheet_name($name_src),
				'xml' => '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $head . '" topLeftCell="A' . ($head + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
					. '<cols>' . $colxml . '</cols>'
					. '<sheetData>' . implode('', $rows) . '</sheetData>'
					. (count($sec_rows) ? '<autoFilter ref="A' . $head . ':' . $last . $last_data . '"/>' : ''),
			);
		}

		$ws = function ($body) {
			return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $body . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape"/></worksheet>';
		};
		$created = gmdate('Y-m-d\TH:i:s\Z');
		$ov = ''; $wbs = ''; $rels = '';
		foreach ($sheets as $i => $s) {
			$ov .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wbs .= '<sheet name="' . self::xml($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
			$rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
		}
		$files = array(
			array('name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ov . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>'),
			array('name' => '_rels/.rels', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>'),
			array('name' => 'docProps/core.xml', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::xml($title) . '</dc:title><dc:creator>TicketJunkies.com</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>'),
			array('name' => 'xl/workbook.xml', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>' . $wbs . '</sheets></workbook>'),
			array('name' => 'xl/_rels/workbook.xml.rels', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'),
			array('name' => 'xl/styles.xml', 'data' => $styles),
		);
		foreach ($sheets as $i => $s) $files[] = array('name' => 'xl/worksheets/sheet' . ($i + 1) . '.xml', 'data' => $ws($s['xml']));
		return self::zip($files);
	}

	// ============================================================ DOCX
	private static function w_run($text, array $o = array()): string {
		$b = !empty($o['b']); $i = !empty($o['i']); $sz = isset($o['sz']) ? $o['sz'] : 18; $color = isset($o['color']) ? $o['color'] : '';
		return '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>' . ($b ? '<w:b/>' : '') . ($i ? '<w:i/>' : '') . ($color ? '<w:color w:val="' . $color . '"/>' : '') . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr><w:t xml:space="preserve">' . self::xml($text) . '</w:t></w:r>';
	}

	private static function w_para(string $runs, array $o = array()): string {
		$after = isset($o['after']) ? $o['after'] : 80; $before = isset($o['before']) ? $o['before'] : 0;
		$align = isset($o['align']) ? $o['align'] : ''; $keep = !empty($o['keepNext']);
		return '<w:p><w:pPr>' . ($keep ? '<w:keepNext/>' : '') . '<w:spacing w:before="' . $before . '" w:after="' . $after . '"/>' . ($align ? '<w:jc w:val="' . $align . '"/>' : '') . '</w:pPr>' . $runs . '</w:p>';
	}

	public static function docx(array $model): string {
		$PAGE_W = 15840 - 1440;
		$borders = '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="D5D9E0"/><w:left w:val="single" w:sz="4" w:color="D5D9E0"/><w:bottom w:val="single" w:sz="4" w:color="D5D9E0"/><w:right w:val="single" w:sz="4" w:color="D5D9E0"/><w:insideH w:val="single" w:sz="4" w:color="E5E7EB"/><w:insideV w:val="single" w:sz="4" w:color="E5E7EB"/></w:tblBorders>';
		$table = function (array $cols, array $rows, $totals) use ($PAGE_W, $borders) {
			$cols = array_values($cols);
			$total = 0;
			foreach ($cols as $c) $total += self::js_number(self::col_width($c));
			$widths = array();
			foreach ($cols as $c) $widths[] = (int) floor((self::js_number(self::col_width($c)) / $total) * $PAGE_W);
			$cell = function ($text, $w, array $o = array()) {
				$header = !empty($o['header']); $shade = isset($o['shade']) ? $o['shade'] : ''; $bold = !empty($o['bold']); $right = !empty($o['right']);
				$runs = '';
				foreach (explode("\n", self::jstr($text, 'null')) as $k => $ln) $runs .= ($k ? '<w:r><w:br/></w:r>' : '') . self::w_run($ln, array('b' => $header || $bold, 'sz' => 15, 'color' => $header ? 'FFFFFF' : ''));
				return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ($shade ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $shade . '"/>' : '') . '<w:tcMar><w:top w:w="40" w:type="dxa"/><w:bottom w:w="40" w:type="dxa"/></w:tcMar></w:tcPr>'
					. '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/>' . ($right ? '<w:jc w:val="right"/>' : '') . '</w:pPr>' . $runs . '</w:p></w:tc>';
			};
			$is_right = function ($i) use ($cols) { $t = self::col_type($cols, $i); return $t === 'money' || $t === 'int'; };
			$head = '<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>';
			foreach ($cols as $i => $c) $head .= $cell(self::g($c, 'label'), $widths[$i], array('header' => true, 'shade' => '376EB8', 'right' => $is_right($i)));
			$head .= '</w:tr>';
			$body = '';
			foreach (array_values($rows) as $ri => $r) {
				$body .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
				foreach (array_values($r) as $i => $v) $body .= $cell(self::cell_text($v, self::col_type($cols, $i)), isset($widths[$i]) ? $widths[$i] : 0, array('shade' => $ri % 2 ? 'F6F7FB' : '', 'right' => $is_right($i)));
				$body .= '</w:tr>';
			}
			$tot = '';
			if (self::truthy($totals)) {
				$tot = '<w:tr>';
				foreach (array_values($totals) as $i => $v) $tot .= $cell(($v === '' || $v === null) ? '' : self::cell_text($v, self::col_type($cols, $i)), isset($widths[$i]) ? $widths[$i] : 0, array('bold' => true, 'shade' => 'EEF2F7', 'right' => $is_right($i)));
				$tot .= '</w:tr>';
			}
			$grid = '';
			foreach ($widths as $w) $grid .= '<w:gridCol w:w="' . $w . '"/>';
			return '<w:tbl><w:tblPr><w:tblW w:w="' . $PAGE_W . '" w:type="dxa"/>' . $borders . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>' . $head . $body . $tot . '</w:tbl>';
		};
		$brand = self::w_run('TICKET', array('b' => true, 'sz' => 30, 'color' => '84D65F')) . self::w_run('JUNK', array('b' => true, 'sz' => 30, 'color' => '376EB8')) . self::w_run('IES', array('b' => true, 'sz' => 30, 'color' => 'EB742A')) . self::w_run('.COM', array('b' => true, 'sz' => 30, 'color' => '376EB8'));
		$title = self::g($model, 'title');
		$body = self::w_para($brand, array('after' => 60))
			. self::w_para(self::w_run($title, array('b' => true, 'sz' => 36)), array('after' => 40))
			. self::w_para(self::w_run(self::g($model, 'subtitle'), array('i' => true, 'sz' => 18, 'color' => '6B7280')), array('after' => 200));
		$meta = self::meta($model);
		if (count($meta)) {
			$body .= self::w_para(self::w_run('Summary', array('b' => true, 'sz' => 24, 'color' => '376EB8')), array('before' => 120, 'after' => 80, 'keepNext' => true));
			$mrows = array();
			foreach ($meta as $kv) {
				$k = isset($kv[0]) ? $kv[0] : null; $v = isset($kv[1]) ? $kv[1] : null;
				$mrows[] = array($k, (self::is_num($v) && self::is_money_key($k)) ? self::fmt_money($v) : self::jstr($v, 'null'));
			}
			$body .= $table(array(array('label' => 'Measure', 'width' => 30), array('label' => 'Value', 'width' => 50)), $mrows, null);
		}
		foreach (self::sections($model) as $s) {
			$body .= self::w_para(self::w_run(self::g($s, 'title'), array('b' => true, 'sz' => 24, 'color' => '376EB8')), array('before' => 280, 'after' => 80, 'keepNext' => true));
			$rows = self::g($s, 'rows', array());
			$body .= count($rows) ? $table(self::g($s, 'columns', array()), $rows, self::g($s, 'totals')) : self::w_para(self::w_run('No data for this period.', array('i' => true, 'color' => '6B7280')));
		}
		$body .= self::w_para(self::w_run('Generated by TicketJunkies.com ' . "\u{00b7}" . ' ' . self::fmt_date(self::now_local()), array('i' => true, 'sz' => 14, 'color' => '9AA3B2')), array('before' => 300));
		$sect = '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360" w:gutter="0"/></w:sectPr>';
		$doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body>' . $body . $sect . '</w:body></w:document>';
		$created = gmdate('Y-m-d\TH:i:s\Z');
		return self::zip(array(
			array('name' => '[Content_Types].xml', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>'),
			array('name' => '_rels/.rels', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>'),
			array('name' => 'docProps/core.xml', 'data' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::xml($title) . '</dc:title><dc:creator>TicketJunkies.com</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created></cp:coreProperties>'),
			array('name' => 'word/document.xml', 'data' => $doc),
		));
	}

	// ============================================================ PDF helpers
	// Helvetica glyph widths (per 1000 em) for ASCII 32..126
	const HW = array(278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584);
	const WIN = array(0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x2018 => 0x91, 0x2019 => 0x92, 0x201c => 0x93, 0x201d => 0x94, 0x2026 => 0x85, 0x20ac => 0x80, 0x2122 => 0x99);

	/** Decode UTF-8 to code points (invalid bytes -> U+FFFD, like Node's Buffer decoding). */
	private static function code_points(string $s): array {
		$out = array(); $n = strlen($s); $i = 0;
		while ($i < $n) {
			$c = ord($s[$i]);
			if ($c < 0x80) { $out[] = $c; $i++; continue; }
			$len = $c >= 0xF0 && $c <= 0xF4 ? 4 : ($c >= 0xE0 ? 3 : ($c >= 0xC2 && $c < 0xE0 ? 2 : 0));
			if ($len && $i + $len <= $n) {
				$cp = $len === 2 ? $c & 0x1F : ($len === 3 ? $c & 0x0F : $c & 0x07);
				$ok = true;
				for ($k = 1; $k < $len; $k++) { $b = ord($s[$i + $k]); if (($b & 0xC0) !== 0x80) { $ok = false; break; } $cp = ($cp << 6) | ($b & 0x3F); }
				if ($ok && !($len === 3 && ($cp < 0x800 || ($cp >= 0xD800 && $cp <= 0xDFFF))) && !($len === 4 && ($cp < 0x10000 || $cp > 0x10FFFF))) { $out[] = $cp; $i += $len; continue; }
			}
			$out[] = 0xFFFD; $i++;
		}
		return $out;
	}

	/** UTF-8 text -> WinAnsi byte string */
	private static function win_ansi($s): string {
		$out = '';
		foreach (self::code_points(self::jstr($s, '')) as $c) {
			if ($c === 0x2713) $out .= '+';
			elseif (isset(self::WIN[$c])) $out .= chr(self::WIN[$c]);
			elseif ($c === 10 || $c === 13 || $c === 9) $out .= ' ';
			elseif ($c >= 32 && $c <= 126) $out .= chr($c);
			elseif ($c >= 160 && $c <= 255) $out .= chr($c);
			elseif ($c > 0xffff || ($c >= 0x2600 && $c <= 0x27bf) || $c === 0xfe0f) continue;
			else $out .= '?';
		}
		return $out;
	}

	private static function glyph_w(int $b, bool $bold): float {
		$w = ($b >= 32 && $b <= 126) ? self::HW[$b - 32] : ($b === 0x95 ? 350 : ($b === 0x97 ? 1000 : 556));
		return $w * ($bold ? 1.06 : 1);
	}

	private static function text_w(string $buf, $size, bool $bold = false): float {
		$w = 0;
		$n = strlen($buf);
		for ($i = 0; $i < $n; $i++) $w += self::glyph_w(ord($buf[$i]), $bold);
		return ($w * $size) / 1000;
	}

	private static function pdf_str(string $buf): string {
		$o = '(';
		$n = strlen($buf);
		for ($i = 0; $i < $n; $i++) {
			$b = ord($buf[$i]);
			if ($b === 0x28 || $b === 0x29 || $b === 0x5c) $o .= '\\' . chr($b);
			elseif ($b < 32 || $b > 126) $o .= '\\' . str_pad(decoct($b), 3, '0', STR_PAD_LEFT);
			else $o .= chr($b);
		}
		return $o . ')';
	}

	/** Word-wrap; returns WinAnsi byte-string lines. */
	private static function wrap($text, $width, $size, bool $bold, int $max_lines = 3): array {
		$buf = self::win_ansi($text);
		if (self::text_w($buf, $size, $bold) <= $width) return array($buf);
		$words = preg_split('/ +/', $buf);
		$lines = array(); $cur = '';
		$fits = function ($s) use ($size, $bold, $width) { return self::text_w($s, $size, $bold) <= $width; };
		foreach ($words as $w) {
			while (!$fits($w) && strlen($w) > 1) {
				$k = strlen($w);
				while ($k > 1 && !$fits(($cur !== '' ? $cur . ' ' : '') . substr($w, 0, $k))) $k--;
				if ($k <= 1 && $cur !== '') { $lines[] = $cur; $cur = ''; continue; }
				$lines[] = ($cur !== '' ? $cur . ' ' : '') . substr($w, 0, $k); $cur = ''; $w = (string) substr($w, $k);
			}
			$t = $cur !== '' ? $cur . ' ' . $w : $w;
			if ($fits($t)) $cur = $t; else { if ($cur !== '') $lines[] = $cur; $cur = $w; }
		}
		if ($cur !== '') $lines[] = $cur;
		if (count($lines) > $max_lines) {
			$keep = array_slice($lines, 0, $max_lines);
			$last = $keep[$max_lines - 1];
			while (strlen($last) && !$fits($last . '...')) $last = substr($last, 0, -1);
			$keep[$max_lines - 1] = $last . '...';
			return $keep;
		}
		return $lines;
	}

	private static function rgb(string $hex): string {
		$p = array();
		foreach (array(0, 2, 4) as $i) $p[] = self::to_fixed(hexdec(substr($hex, $i, 2)) / 255, 3);
		return implode(' ', $p);
	}

	private static function f2($v): string { return self::to_fixed($v, 2); }

	/** Assemble objects (strings or ['stream'=>..., 'dict'=>...]) into a PDF file. */
	private static function assemble(array $objs, int $catalog, int $info): string {
		$out = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
		$xref = array();
		foreach ($objs as $i => $o) {
			$xref[] = strlen($out);
			if (is_array($o)) $out .= ($i + 1) . " 0 obj\n" . $o['dict'] . "\nstream\n" . $o['stream'] . "\nendstream\nendobj\n";
			else $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$pos = strlen($out);
		$xr = "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
		foreach ($xref as $p) $xr .= str_pad((string) $p, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
		$xr .= "trailer\n<< /Size " . (count($objs) + 1) . ' /Root ' . $catalog . ' 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $pos . "\n%%EOF\n";
		return $out . $xr;
	}

	// ============================================================ report PDF
	public static function pdf(array $model): string {
		$W = 792; $H = 612; $M = 34; $CW = $W - 2 * $M;
		$pages = array(); $ops = array(); $y = 0;
		$title = self::g($model, 'title');
		$text = function ($x, $yy, $buf, $size, $bold = false, $color = '1B1F2A') use (&$ops) {
			$ops[] = 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::js_num($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::f2($x) . ' ' . self::f2($yy) . ' Td ' . self::pdf_str($buf) . ' Tj ET';
		};
		$rect = function ($x, $yy, $w, $h, $color) use (&$ops) {
			$ops[] = self::rgb($color) . ' rg ' . self::f2($x) . ' ' . self::f2($yy) . ' ' . self::f2($w) . ' ' . self::f2($h) . ' re f';
		};
		$line = function ($x1, $y1, $x2, $y2, $color = 'E5E7EB', $w = 0.5) use (&$ops) {
			$ops[] = self::rgb($color) . ' RG ' . self::js_num($w) . ' w ' . self::f2($x1) . ' ' . self::f2($y1) . ' m ' . self::f2($x2) . ' ' . self::f2($y2) . ' l S';
		};
		$new_page = function () use (&$ops, &$pages, &$y, $text, $line, $W, $H, $M, $title) {
			if (count($ops)) $pages[] = $ops;
			$ops = array();
			$x = $M;
			foreach (array(array('TICKET', '84D65F'), array('JUNK', '376EB8'), array('IES', 'EB742A'), array('.COM', '376EB8')) as $tc) {
				$b = self::win_ansi($tc[0]); $text($x, $H - $M - 12, $b, 13, true, $tc[1]); $x += self::text_w($b, 13, true);
			}
			$sub = self::win_ansi($title); $text($W - $M - self::text_w($sub, 8, false), $H - $M - 10, $sub, 8, false, '6B7280');
			$line($M, $H - $M - 20, $W - $M, $H - $M - 20, 'EB742A', 1.2);
			$y = $H - $M - 34;
		};
		$ensure = function ($h) use (&$y, $new_page, $M) {
			if ($y - $h < $M + 18) { $new_page(); return true; }
			return false;
		};

		$new_page();
		$text($M, $y - 16, self::win_ansi($title), 18, true); $y -= 24;
		$text($M, $y - 9, self::win_ansi(self::g($model, 'subtitle')), 9, false, '6B7280'); $y -= 22;

		$meta = self::meta($model);
		if (count($meta)) {
			$boxW = ($CW - 3 * 8) / 4; $boxH = 38;
			$accent = array('376EB8', '84D65F', 'EB742A', '376EB8');
			foreach (array_values($meta) as $i => $kv) {
				$k = isset($kv[0]) ? $kv[0] : null; $v = isset($kv[1]) ? $kv[1] : null;
				if ($i % 4 === 0) { $ensure($boxH + 8); if ($i) $y -= $boxH + 8; }
				$x = $M + ($i % 4) * ($boxW + 8);
				$rect($x, $y - $boxH, $boxW, $boxH, 'F2F4F8');
				$rect($x, $y - 2.5, $boxW, 2.5, $accent[$i % 4]);
				$text($x + 8, $y - 14, self::win_ansi($k), 7.5, false, '6B7280');
				$val = (self::is_num($v) && self::is_money_key($k)) ? self::fmt_money($v) : self::jstr($v, 'null');
				$wl = self::wrap($val, $boxW - 16, 12, true, 1);
				$text($x + 8, $y - 30, $wl[0], 12, true);
			}
			$y -= $boxH + 18;
		}

		$FS = 7.2; $LH = 9; $PAD = 3;
		foreach (self::sections($model) as $sec) {
			$cols = array_values(self::g($sec, 'columns', array()));
			$sec_title = self::g($sec, 'title');
			$total = 0;
			foreach ($cols as $c) $total += self::js_number(self::col_width($c));
			$widths = array();
			foreach ($cols as $c) $widths[] = (self::js_number(self::col_width($c)) / $total) * $CW;
			$is_right = function ($i) use ($cols) { $t = self::col_type($cols, $i); return $t === 'money' || $t === 'int'; };
			$draw_head = function () use (&$y, $cols, $widths, $rect, $text, $is_right, $FS, $LH, $PAD, $M, $CW) {
				$hl = array(); $maxl = 0;
				foreach ($cols as $i => $c) { $hl[$i] = self::wrap(self::g($c, 'label'), $widths[$i] - 2 * $PAD, $FS, true, 2); $maxl = max($maxl, count($hl[$i])); }
				$hh = $maxl * $LH + 2 * $PAD;
				$rect($M, $y - $hh, $CW, $hh, '376EB8');
				$x = $M;
				foreach ($hl as $i => $ls) {
					$right = $is_right($i);
					foreach ($ls as $k => $b) $text($right ? $x + $widths[$i] - $PAD - self::text_w($b, $FS, true) : $x + $PAD, $y - $PAD - ($k + 1) * $LH + 2, $b, $FS, true, 'FFFFFF');
					$x += $widths[$i];
				}
				$y -= $hh;
			};
			$ensure(46);
			$text($M, $y - 12, self::win_ansi($sec_title), 12, true, '376EB8'); $y -= 18;
			$rows = self::g($sec, 'rows', array());
			if (!count($rows)) { $text($M, $y - 9, self::win_ansi('No data for this period.'), 8.5, false, '6B7280'); $y -= 22; continue; }
			$draw_head();
			$all = array();
			foreach ($rows as $r) $all[] = array(array_values($r), false);
			$totals = self::g($sec, 'totals');
			if (self::truthy($totals)) $all[] = array(array_values($totals), true);
			foreach ($all as $ri => $item) {
				list($r, $is_tot) = $item;
				$cells = array(); $maxl = 1;
				foreach ($r as $i => $v) {
					$cells[$i] = self::wrap(($v === '' || $v === null) ? '' : self::cell_text($v, self::col_type($cols, $i)), (isset($widths[$i]) ? $widths[$i] : 0) - 2 * $PAD, $FS, $is_tot, 3);
					$maxl = max($maxl, count($cells[$i]));
				}
				$rh = $maxl * $LH + 2 * $PAD;
				if ($ensure($rh)) { $text($M, $y - 10, self::win_ansi(self::jstr($sec_title, 'undefined') . ' (continued)'), 9, true, '376EB8'); $y -= 16; $draw_head(); }
				if ($is_tot) $rect($M, $y - $rh, $CW, $rh, 'EEF2F7');
				elseif ($ri % 2) $rect($M, $y - $rh, $CW, $rh, 'F7F8FB');
				$x = $M;
				foreach ($cells as $i => $ls) {
					$wi = isset($widths[$i]) ? $widths[$i] : 0;
					$right = $is_right($i);
					foreach ($ls as $k => $b) $text($right ? $x + $wi - $PAD - self::text_w($b, $FS, $is_tot) : $x + $PAD, $y - $PAD - ($k + 1) * $LH + 2, $b, $FS, $is_tot);
					$x += $wi;
				}
				$y -= $rh;
				$line($M, $y, $M + $CW, $y);
			}
			$y -= 16;
		}
		$pages[] = $ops;

		// assemble
		$objs = array();
		$add = function ($s) use (&$objs) { $objs[] = $s; return count($objs); };
		$catalog = $add(null); $pages_obj = $add(null);
		$f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
		$f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
		$info = $add('<< /Title ' . self::pdf_str(self::win_ansi($title)) . ' /Producer (TicketJunkies.com) /CreationDate (D:' . gmdate('YmdHis') . ') >>');
		$kids = array();
		$gen = self::fmt_date(self::now_local());
		$np = count($pages);
		foreach ($pages as $i => $p) {
			$footer = array('BT /F1 7 Tf ' . self::rgb('9AA3B2') . ' rg ' . $M . ' ' . ($M - 14) . ' Td ' . self::pdf_str(self::win_ansi("TicketJunkies.com  \u{00b7}  " . $gen)) . ' Tj ET');
			$pg = self::win_ansi('Page ' . ($i + 1) . ' of ' . $np);
			$footer[] = 'BT /F1 7 Tf ' . self::rgb('9AA3B2') . ' rg ' . self::f2($W - $M - self::text_w($pg, 7)) . ' ' . ($M - 14) . ' Td ' . self::pdf_str($pg) . ' Tj ET';
			$content = implode("\n", array_merge($p, $footer));
			$deflated = gzcompress($content);
			$cs = $add(array('stream' => $deflated, 'dict' => '<< /Length ' . strlen($deflated) . ' /Filter /FlateDecode >>'));
			$kids[] = $add('<< /Type /Page /Parent ' . $pages_obj . ' 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >> >> /Contents ' . $cs . ' 0 R >>');
		}
		$objs[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pages_obj . ' 0 R >>';
		$objs[$pages_obj - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(function ($k) { return $k . ' 0 R'; }, $kids)) . '] /Count ' . count($kids) . ' >>';
		return self::assemble($objs, $catalog, $info);
	}

	// ============================================================ TICKET PDF (one ticket per page, vector QR)
	public static function tickets_pdf(array $order, array $ev, string $site = ''): string {
		$W = 612; $H = 792;
		$tickets = array_values(isset($order['tickets']) && is_array($order['tickets']) ? $order['tickets'] : array());
		$nt = count($tickets);
		$order_id = self::jstr(self::g($order, 'id'), '');
		$pages = array();
		foreach ($tickets as $idx => $t) {
			$ops = array();
			$text = function ($x, $y, $s, $size, $bold = false, $color = '1B1F2A') use (&$ops) {
				$ops[] = 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . self::js_num($size) . ' Tf ' . self::rgb($color) . ' rg ' . self::f2($x) . ' ' . self::f2($y) . ' Td ' . self::pdf_str(self::win_ansi($s)) . ' Tj ET';
			};
			$center = function ($y, $s, $size, $bold, $color, $x0 = 0, $w = null) use ($text, $W) {
				if ($w === null) $w = $W;
				$b = self::win_ansi($s);
				$text($x0 + ($w - self::text_w($b, $size, $bold)) / 2, $y, $s, $size, $bold, $color);
			};
			$rect = function ($x, $y, $w, $h, $color) use (&$ops) {
				$ops[] = self::rgb($color) . ' rg ' . self::f2($x) . ' ' . self::f2($y) . ' ' . self::f2($w) . ' ' . self::f2($h) . ' re f';
			};
			$cx = 56; $cw = $W - 112; $top = $H - 56; $ch = 600; $bottom = $top - $ch;
			$ops[] = self::rgb('EB742A') . ' RG 2 w [6 4] 0 d ' . $cx . ' ' . $bottom . ' ' . $cw . ' ' . $ch . ' re S [] 0 d';
			$rect($cx, $top - 70, $cw, 70, '1B1F2A');
			$bx = $cx + 24;
			foreach (array(array('TICKET', '84D65F'), array('JUNK', '6FA0E6'), array('IES', 'EB742A'), array('.COM', '6FA0E6')) as $sc) {
				$b = self::win_ansi($sc[0]); $text($bx, $top - 44, $sc[0], 22, true, $sc[1]); $bx += self::text_w($b, 22, true);
			}
			$tn = 'Ticket ' . ($idx + 1) . ' of ' . $nt;
			$text($cx + $cw - 24 - self::text_w(self::win_ansi($tn), 11, false), $top - 41, $tn, 11, false, 'CFD5DF');
			$tier = self::g($t, 'tierName');
			$center($top - 112, self::upper(self::jstr(self::truthy($tier) ? $tier : 'Admission')), 14, true, 'EB742A', $cx, $cw);
			$ev_title = self::g($ev, 'title');
			$lines = self::wrap(self::truthy($ev_title) ? $ev_title : 'Event', $cw - 60, 24, true, 2);
			$y = $top - 146;
			foreach ($lines as $l) {
				// $l is already WinAnsi bytes; re-encode as UTF-8 latin1 so win_ansi() maps it back to the same bytes
				$center($y, self::latin1_to_utf8($l), 24, true, '1B1F2A', $cx, $cw); $y -= 30;
			}
			$center($y - 2, self::fmt_when(self::g($ev, 'startsAt')), 12.5, false, '374151', $cx, $cw);
			$parts = array();
			foreach (array('venue', 'address', 'city') as $k) { $pv = self::g($ev, $k); if (self::truthy($pv)) $parts[] = self::jstr($pv); }
			$center($y - 20, self::js_slice(implode(', ', $parts), 0, 90), 11.5, false, '6B7280', $cx, $cw);
			// QR
			$code = self::jstr(self::g($t, 'code'), '');
			$m = TJ_QR::matrix($code); $n = count($m); $size = 250; $mod = $size / ($n + 8);
			$qx = ($W - $size) / 2; $qy = $y - 52 - $size;
			$rect($qx, $qy, $size, $size, 'FFFFFF');
			$ops[] = self::rgb('000000') . ' rg';
			$ms = self::f2($mod + 0.05);
			foreach ($m as $r => $row) foreach ($row as $c => $dark) {
				if ($dark) $ops[] = self::f2($qx + ($c + 4) * $mod) . ' ' . self::f2($qy + $size - ($r + 5) * $mod) . ' ' . $ms . ' ' . $ms . ' re';
			}
			$ops[] = 'f';
			$codeY = $qy - 34;
			$cb = self::win_ansi($code);
			$ops[] = 'BT /F3 22 Tf ' . self::rgb('376EB8') . ' rg ' . self::f2(($W - strlen($cb) * 13.2) / 2) . ' ' . self::js_num($codeY) . ' Td ' . self::pdf_str($cb) . ' Tj ET';
			$ops[] = self::rgb('E5E7EB') . ' RG 1 w ' . self::js_num($cx + 30) . ' ' . self::js_num($codeY - 22) . ' m ' . self::js_num($cx + $cw - 30) . ' ' . self::js_num($codeY - 22) . ' l S';
			$price = self::g($t, 'price');
			$details = array(
				array('Attendee', self::g($t, 'holderName') ? $t['holderName'] : self::g($order, 'buyerName')),
				array('Order #', self::upper(self::js_slice($order_id, -8))),
				array('Price', self::truthy(self::g($order, 'comp')) ? 'Complimentary' : (self::truthy($price) ? '$' . self::to_fixed($price, 2) : 'Free')),
			);
			$colW = ($cw - 60) / 3;
			foreach ($details as $i => $kv) {
				$x0 = $cx + 30 + $i * $colW;
				$center($codeY - 44, strtoupper($kv[0]), 8.5, true, '9AA3B2', $x0, $colW);
				$center($codeY - 60, self::js_slice(self::jstr($kv[1], 'undefined'), 0, 28), 12, true, '1B1F2A', $x0, $colW);
			}
			if (self::g($order, 'status') === 'refunded') $center($codeY - 90, 'REFUNDED - THIS TICKET IS NOT VALID', 13, true, 'C62828', $cx, $cw);
			elseif (self::truthy(self::g($t, 'checkedIn'))) $center($codeY - 90, 'Already checked in', 11, true, '3E7F22', $cx, $cw);
			$center($bottom - 26, 'Show this QR code at the door - on your phone or printed. Each ticket admits one person and can be scanned once.', 9, false, '6B7280');
			if ($site !== '') $center($bottom - 40, 'Your tickets are always in your dashboard: ' . $site . '/#/tickets', 9, false, '6B7280');
			$pages[] = implode("\n", $ops);
		}

		$objs = array();
		$add = function ($s) use (&$objs) { $objs[] = $s; return count($objs); };
		$catalog = $add(null); $pages_obj = $add(null);
		$f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
		$f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
		$f3 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>');
		$info = $add('<< /Title ' . self::pdf_str(self::win_ansi('Tickets - ' . self::jstr(self::g($ev, 'title'), ''))) . ' /Producer (TicketJunkies.com) >>');
		$kids = array();
		foreach ($pages as $content) {
			$d = gzcompress($content);
			$cs = $add(array('stream' => $d, 'dict' => '<< /Length ' . strlen($d) . ' /Filter /FlateDecode >>'));
			$kids[] = $add('<< /Type /Page /Parent ' . $pages_obj . ' 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R /F3 ' . $f3 . ' 0 R >> >> /Contents ' . $cs . ' 0 R >>');
		}
		$objs[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pages_obj . ' 0 R >>';
		$objs[$pages_obj - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(function ($k) { return $k . ' 0 R'; }, $kids)) . '] /Count ' . count($kids) . ' >>';
		return self::assemble($objs, $catalog, $info);
	}

	private static function latin1_to_utf8(string $s): string {
		$o = '';
		$n = strlen($s);
		for ($i = 0; $i < $n; $i++) {
			$c = ord($s[$i]);
			if ($c < 0x80) $o .= $s[$i];
			elseif ($c < 0xA0) { // C1 range: WinAnsi bytes produced by win_ansi(); map back to the source code point
				$cp = array_search($c, self::WIN, true);
				$o .= $cp === false ? '?' : self::cp_to_utf8($cp);
			} else $o .= chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F));
		}
		return $o;
	}

	private static function cp_to_utf8(int $cp): string {
		if ($cp < 0x80) return chr($cp);
		if ($cp < 0x800) return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
		return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
	}
}
