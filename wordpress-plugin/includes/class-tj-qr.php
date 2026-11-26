<?php if (!defined('ABSPATH')) exit;
/**
 * TicketJunkies — self-contained QR Code generator (byte mode, error correction level M, versions 1–10)
 * plus PNG and SVG renderers. Port of qr.mjs; produces the identical module matrix. No GD required.
 */
final class TJ_QR {
	const ECC_PER_BLOCK_M = array(0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26);
	const NUM_BLOCKS_M = array(0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5);
	const FORMAT_ECL_M = 0;

	private static function gf_mul(int $x, int $y): int {
		$z = 0;
		for ($i = 7; $i >= 0; $i--) {
			$z = ($z << 1) ^ (($z >> 7) * 0x11d);
			$z ^= (($y >> $i) & 1) * $x;
		}
		return $z & 0xff;
	}

	private static function rs_divisor(int $degree): array {
		$r = array_fill(0, $degree, 0);
		$r[$degree - 1] = 1;
		$root = 1;
		for ($i = 0; $i < $degree; $i++) {
			for ($j = 0; $j < $degree; $j++) {
				$r[$j] = self::gf_mul($r[$j], $root);
				if ($j + 1 < $degree) $r[$j] ^= $r[$j + 1];
			}
			$root = self::gf_mul($root, 0x02);
		}
		return $r;
	}

	private static function rs_remainder(array $data, array $divisor): array {
		$n = count($divisor);
		$r = array_fill(0, $n, 0);
		foreach ($data as $b) {
			$f = $b ^ array_shift($r);
			$r[] = 0;
			for ($i = 0; $i < $n; $i++) $r[$i] ^= self::gf_mul($divisor[$i], $f);
		}
		return $r;
	}

	private static function raw_data_modules(int $ver): int {
		$r = (16 * $ver + 128) * $ver + 64;
		if ($ver >= 2) {
			$n = intdiv($ver, 7) + 2;
			$r -= (25 * $n - 10) * $n - 55;
			if ($ver >= 7) $r -= 36;
		}
		return $r;
	}

	private static function data_codewords(int $ver): int {
		return intdiv(self::raw_data_modules($ver), 8) - self::ECC_PER_BLOCK_M[$ver] * self::NUM_BLOCKS_M[$ver];
	}

	private static function alignment_positions(int $ver): array {
		if ($ver === 1) return array();
		$num = intdiv($ver, 7) + 2;
		$step = (int) ceil(($ver * 4 + 4) / ($num * 2 - 2)) * 2;
		$result = array(6);
		for ($pos = $ver * 4 + 10; count($result) < $num; $pos -= $step) array_splice($result, 1, 0, array($pos));
		return $result;
	}

	private static function bit(int $x, int $i): bool {
		return (($x >> $i) & 1) !== 0;
	}

	private static function mask_fn(int $m, int $x, int $y): bool {
		switch ($m) {
			case 0: return ($x + $y) % 2 === 0;
			case 1: return $y % 2 === 0;
			case 2: return $x % 3 === 0;
			case 3: return ($x + $y) % 3 === 0;
			case 4: return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
			case 5: return (($x * $y) % 2) + (($x * $y) % 3) === 0;
			case 6: return ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0;
			default: return ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0;
		}
	}

	/** @return bool[][] rows of modules, true = dark */
	public static function matrix(string $text): array {
		$bytes = array_values(unpack('C*', $text) ?: array());
		$nb = count($bytes);
		$ver = 1;
		for (; $ver <= 10; $ver++) {
			$cc = $ver <= 9 ? 8 : 16;
			if (4 + $cc + $nb * 8 <= self::data_codewords($ver) * 8) break;
		}
		if ($ver > 10) throw new InvalidArgumentException('QR payload too long');

		// --- data bit stream ---
		$bits = array();
		$push = function ($val, $len) use (&$bits) {
			for ($i = $len - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1;
		};
		$push(0x4, 4);
		$push($nb, $ver <= 9 ? 8 : 16);
		foreach ($bytes as $b) $push($b, 8);
		$cap = self::data_codewords($ver) * 8;
		$push(0, min(4, $cap - count($bits)));
		$push(0, (8 - (count($bits) % 8)) % 8);
		for ($pad = 0xec; count($bits) < $cap; $pad ^= 0xec ^ 0x11) $push($pad, 8);
		$data = array();
		for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
			$v = 0;
			for ($k = 0; $k < 8; $k++) $v = ($v << 1) | $bits[$i + $k];
			$data[] = $v;
		}

		// --- error correction + interleave ---
		$num_blocks = self::NUM_BLOCKS_M[$ver];
		$ecc_len = self::ECC_PER_BLOCK_M[$ver];
		$raw_cw = intdiv(self::raw_data_modules($ver), 8);
		$num_short = $num_blocks - ($raw_cw % $num_blocks);
		$short_len = intdiv($raw_cw, $num_blocks);
		$div = self::rs_divisor($ecc_len);
		$blocks = array();
		for ($i = 0, $k = 0; $i < $num_blocks; $i++) {
			$len = $short_len - $ecc_len + ($i < $num_short ? 0 : 1);
			$dat = array_slice($data, $k, $len);
			$k += $len;
			$ecc = self::rs_remainder($dat, $div);
			if ($i < $num_short) $dat[] = 0;
			$blocks[] = array_merge($dat, $ecc);
		}
		$codewords = array();
		$blen = count($blocks[0]);
		for ($i = 0; $i < $blen; $i++) {
			foreach ($blocks as $j => $blk) {
				if ($i !== $short_len - $ecc_len || $j >= $num_short) $codewords[] = $blk[$i];
			}
		}

		// --- module grid ---
		$size = $ver * 4 + 17;
		$mod = array_fill(0, $size, array_fill(0, $size, false));
		$fn = $mod;
		$setF = function ($x, $y, $dark) use (&$mod, &$fn) { $mod[$y][$x] = (bool) $dark; $fn[$y][$x] = true; };

		for ($i = 0; $i < $size; $i++) { $setF(6, $i, $i % 2 === 0); $setF($i, 6, $i % 2 === 0); }
		foreach (array(array(3, 3), array($size - 4, 3), array(3, $size - 4)) as $c) {
			for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
				$d = max(abs($dx), abs($dy));
				$x = $c[0] + $dx; $y = $c[1] + $dy;
				if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) $setF($x, $y, $d !== 2 && $d !== 4);
			}
		}
		$ap = self::alignment_positions($ver);
		$last = count($ap) - 1;
		for ($i = 0; $i < count($ap); $i++) for ($j = 0; $j < count($ap); $j++) {
			if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
			for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $setF($ap[$i] + $dx, $ap[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
		}
		$drawFormat = function ($mask) use ($setF, $size) {
			$d = (self::FORMAT_ECL_M << 3) | $mask;
			$rem = $d;
			for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
			$b = (($d << 10) | $rem) ^ 0x5412;
			for ($i = 0; $i <= 5; $i++) $setF(8, $i, self::bit($b, $i));
			$setF(8, 7, self::bit($b, 6)); $setF(8, 8, self::bit($b, 7)); $setF(7, 8, self::bit($b, 8));
			for ($i = 9; $i < 15; $i++) $setF(14 - $i, 8, self::bit($b, $i));
			for ($i = 0; $i < 8; $i++) $setF($size - 1 - $i, 8, self::bit($b, $i));
			for ($i = 8; $i < 15; $i++) $setF(8, $size - 15 + $i, self::bit($b, $i));
			$setF(8, $size - 8, true);
		};
		$drawFormat(0);
		if ($ver >= 7) {
			$rem = $ver;
			for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1f25);
			$b = ($ver << 12) | $rem;
			for ($i = 0; $i < 18; $i++) {
				$a = $size - 11 + ($i % 3); $c = intdiv($i, 3);
				$setF($a, $c, self::bit($b, $i)); $setF($c, $a, self::bit($b, $i));
			}
		}
		$i = 0;
		$total_bits = count($codewords) * 8;
		for ($right = $size - 1; $right >= 1; $right -= 2) {
			if ($right === 6) $right = 5;
			for ($vert = 0; $vert < $size; $vert++) for ($j = 0; $j < 2; $j++) {
				$x = $right - $j;
				$up = (($right + 1) & 2) === 0;
				$y = $up ? $size - 1 - $vert : $vert;
				if (!$fn[$y][$x] && $i < $total_bits) { $mod[$y][$x] = self::bit($codewords[$i >> 3], 7 - ($i & 7)); $i++; }
			}
		}

		// --- masking: pick lowest penalty ---
		$applyMask = function ($m) use (&$mod, &$fn, $size) {
			for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
				if (!$fn[$y][$x] && self::mask_fn($m, $x, $y)) $mod[$y][$x] = !$mod[$y][$x];
			}
		};
		$penalty = function () use (&$mod, $size) {
			$p = 0; $dark = 0;
			for ($a = 0; $a < $size; $a++) {
				foreach (array(true, false) as $horiz) {
					$run = 1;
					for ($b = 1; $b < $size; $b++) {
						$cur = $horiz ? $mod[$a][$b] : $mod[$b][$a];
						$prev = $horiz ? $mod[$a][$b - 1] : $mod[$b - 1][$a];
						if ($cur === $prev) { $run++; if ($run === 5) $p += 3; elseif ($run > 5) $p++; } else $run = 1;
					}
				}
			}
			for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {
				$c = $mod[$y][$x];
				if ($c === $mod[$y][$x + 1] && $c === $mod[$y + 1][$x] && $c === $mod[$y + 1][$x + 1]) $p += 3;
			}
			foreach ($mod as $row) foreach ($row as $c) if ($c) $dark++;
			$p += intdiv(abs($dark * 20 - $size * $size * 10), $size * $size) * 10;
			return $p;
		};
		$best = 0; $bestP = PHP_INT_MAX;
		for ($m = 0; $m < 8; $m++) {
			$applyMask($m); $drawFormat($m);
			$p = $penalty();
			if ($p < $bestP) { $bestP = $p; $best = $m; }
			$applyMask($m);
		}
		$applyMask($best); $drawFormat($best);
		return $mod;
	}

	// ---------- renderers ----------
	private static function chunk(string $type, string $data): string {
		$td = $type . $data;
		return pack('N', strlen($data)) . $td . pack('N', crc32($td));
	}

	/** 8-bit grayscale PNG, built by hand (no GD). */
	public static function png(string $text, int $scale = 8, int $margin = 4): string {
		$m = self::matrix($text);
		$n = count($m);
		$scale = max(1, $scale); $margin = max(0, $margin);
		$px = ($n + $margin * 2) * $scale;
		$white = "\0" . str_repeat("\xff", $px);
		$raw = '';
		for ($my = -$margin; $my < $n + $margin; $my++) {
			if ($my < 0 || $my >= $n) { $raw .= str_repeat($white, $scale); continue; }
			$row = "\0";
			for ($mx = -$margin; $mx < $n + $margin; $mx++) {
				$dark = $mx >= 0 && $mx < $n && $m[$my][$mx];
				$row .= str_repeat($dark ? "\x00" : "\xff", $scale);
			}
			$raw .= str_repeat($row, $scale);
		}
		$ihdr = pack('NN', $px, $px) . "\x08\x00\x00\x00\x00";
		return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', gzcompress($raw)) . self::chunk('IEND', '');
	}

	public static function svg(string $text, int $margin = 4): string {
		$m = self::matrix($text);
		$n = count($m) + $margin * 2;
		$path = '';
		foreach ($m as $y => $row) foreach ($row as $x => $dark) {
			if ($dark) $path .= 'M' . ($x + $margin) . ' ' . ($y + $margin) . 'h1v1h-1z';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges"><rect width="' . $n . '" height="' . $n . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
	}
}
