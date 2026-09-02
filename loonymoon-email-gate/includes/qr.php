<?php
if (!defined('ABSPATH') && !defined('LMEG_QR_STANDALONE')) exit;

/* ============================================================================
 * Fanloop — self-contained QR generator (byte mode, ECC level L, versions 1-10)
 * ----------------------------------------------------------------------------
 * Pure PHP, no external service and no extensions beyond core: encodes a short
 * string (e.g. a Wallet pass URL) to an inline SVG QR. We roll our own instead
 * of calling a QR web service because the pass URL carries an auth token that
 * must never leave the server to a third party. Output is a crisp vector SVG.
 *
 * Public API:
 *   lmeg_qr_matrix($text)          -> ['size'=>N, 'rows'=>[[0|1,...],...]] | false
 *   lmeg_qr_svg($text, $args=[])   -> '<svg ...>...</svg>' | ''
 * ========================================================================== */

/** GF(256) exp/log tables (primitive poly 0x11D), built once. */
function lmeg_qr_gf() {
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = ['exp' => $exp, 'log' => $log];
}

function lmeg_qr_gmul($a, $b) {
    if ($a === 0 || $b === 0) return 0;
    $g = lmeg_qr_gf();
    return $g['exp'][$g['log'][$a] + $g['log'][$b]];
}

/** Reed-Solomon generator polynomial of the given degree. */
function lmeg_qr_genpoly($deg) {
    $g = [1];
    $gf = lmeg_qr_gf();
    for ($i = 0; $i < $deg; $i++) {
        $ng = array_fill(0, count($g) + 1, 0);
        for ($j = 0; $j < count($g); $j++) {
            $ng[$j]     ^= $g[$j];
            $ng[$j + 1] ^= lmeg_qr_gmul($g[$j], $gf['exp'][$i]);
        }
        $g = $ng;
    }
    return $g;
}

/** ECC codewords for one data block. */
function lmeg_qr_rs($data, $ecLen) {
    $gen = lmeg_qr_genpoly($ecLen);
    $res = array_merge($data, array_fill(0, $ecLen, 0));
    $n = count($data);
    for ($i = 0; $i < $n; $i++) {
        $coef = $res[$i];
        if ($coef !== 0) {
            for ($j = 0; $j <= $ecLen; $j++) $res[$i + $j] ^= lmeg_qr_gmul($gen[$j], $coef);
        }
    }
    return array_slice($res, $n, $ecLen);
}

/**
 * ECC level L capacity + block structure, versions 1-10.
 * [ total_data_codewords, ecc_per_block, [ [num_blocks, data_per_block], ... ] ]
 */
function lmeg_qr_versions() {
    return [
        1  => [19,  7,  [[1, 19]]],
        2  => [34,  10, [[1, 34]]],
        3  => [55,  15, [[1, 55]]],
        4  => [80,  20, [[1, 80]]],
        5  => [108, 26, [[1, 108]]],
        6  => [136, 18, [[2, 68]]],
        7  => [156, 20, [[2, 78]]],
        8  => [194, 24, [[2, 97]]],
        9  => [232, 30, [[2, 116]]],
        10 => [274, 18, [[2, 68], [2, 69]]],
    ];
}

/** Alignment-pattern centre coordinates per version (level-independent). */
function lmeg_qr_align_pos($v) {
    $map = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];
    return $map[$v] ?? [];
}

/** Encode $text (byte mode, ECC-L) to the final interleaved codeword stream + chosen version. */
function lmeg_qr_encode_codewords($text) {
    $bytes = array_values(unpack('C*', $text));
    $len = count($bytes);
    $versions = lmeg_qr_versions();

    // Pick the smallest version whose data capacity holds mode+count+payload.
    $chosen = 0; $spec = null;
    foreach ($versions as $v => $s) {
        $countBits = ($v >= 10) ? 16 : 8;
        $needBits  = 4 + $countBits + 8 * $len;   // mode + char-count + data
        if ($needBits <= $s[0] * 8) { $chosen = $v; $spec = $s; break; }
    }
    if (!$chosen) return false;   // too long for v1-10

    [$totalData, $ecLen, $groups] = $spec;
    $countBits = ($chosen >= 10) ? 16 : 8;

    // Bit stream: mode (0100) + char count + bytes.
    $bits = '';
    $bits .= '0100';
    $bits .= str_pad(decbin($len), $countBits, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);

    // Terminator (up to 4 zero bits), then pad to a byte boundary.
    $cap = $totalData * 8;
    $bits .= str_repeat('0', min(4, $cap - strlen($bits)));
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - (strlen($bits) % 8));

    // Data codewords + pad bytes (0xEC / 0x11 alternating).
    $data = [];
    for ($i = 0; $i < strlen($bits); $i += 8) $data[] = bindec(substr($bits, $i, 8));
    $pad = [0xEC, 0x11]; $pi = 0;
    while (count($data) < $totalData) { $data[] = $pad[$pi & 1]; $pi++; }

    // Split into blocks; compute per-block ECC.
    $blocksData = []; $blocksEcc = []; $offset = 0;
    foreach ($groups as $grp) {
        [$nBlocks, $perBlock] = $grp;
        for ($b = 0; $b < $nBlocks; $b++) {
            $blk = array_slice($data, $offset, $perBlock); $offset += $perBlock;
            $blocksData[] = $blk;
            $blocksEcc[]  = lmeg_qr_rs($blk, $ecLen);
        }
    }

    // Interleave data codewords, then ECC codewords.
    $out = [];
    $maxData = 0; foreach ($blocksData as $blk) $maxData = max($maxData, count($blk));
    for ($i = 0; $i < $maxData; $i++) foreach ($blocksData as $blk) if ($i < count($blk)) $out[] = $blk[$i];
    for ($i = 0; $i < $ecLen; $i++) foreach ($blocksEcc as $blk) $out[] = $blk[$i];

    return ['version' => $chosen, 'codewords' => $out];
}

/* ---- matrix assembly ------------------------------------------------------ */

function lmeg_qr_new_matrix($size) {
    $m = []; $f = [];
    for ($y = 0; $y < $size; $y++) { $m[$y] = array_fill(0, $size, 0); $f[$y] = array_fill(0, $size, false); }
    return [$m, $f];
}

function lmeg_qr_place_finder(&$m, &$fn, $size, $r, $c) {
    for ($dy = -1; $dy <= 7; $dy++) {
        for ($dx = -1; $dx <= 7; $dx++) {
            $y = $r + $dy; $x = $c + $dx;
            if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) continue;
            $fn[$y][$x] = true;
            $on = ($dx >= 0 && $dx <= 6 && ($dy === 0 || $dy === 6)) ||
                  ($dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6)) ||
                  ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4);
            $m[$y][$x] = $on ? 1 : 0;
        }
    }
}

function lmeg_qr_build_matrix($version, $codewords, $mask) {
    $size = 17 + 4 * $version;
    [$m, $fn] = lmeg_qr_new_matrix($size);

    // Finder patterns (with separators handled by the -1..7 border above).
    lmeg_qr_place_finder($m, $fn, $size, 0, 0);
    lmeg_qr_place_finder($m, $fn, $size, 0, $size - 7);
    lmeg_qr_place_finder($m, $fn, $size, $size - 7, 0);

    // Timing patterns.
    for ($i = 8; $i < $size - 8; $i++) {
        if (!$fn[6][$i]) { $fn[6][$i] = true; $m[6][$i] = ($i % 2 === 0) ? 1 : 0; }
        if (!$fn[$i][6]) { $fn[$i][6] = true; $m[$i][6] = ($i % 2 === 0) ? 1 : 0; }
    }

    // Alignment patterns (skip any overlapping a finder).
    $pos = lmeg_qr_align_pos($version);
    foreach ($pos as $ry) {
        foreach ($pos as $cx) {
            $skip = false;
            // Skip only the centres that collide with the three finder zones;
            // centres that merely cross a timing line are still drawn (they
            // override the timing modules, per spec).
            foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as $f) {
                if (abs($ry - ($f[0] + 3)) <= 4 && abs($cx - ($f[1] + 3)) <= 4) { $skip = true; break; }
            }
            if ($skip) continue;
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $y = $ry + $dy; $x = $cx + $dx;
                    $fn[$y][$x] = true;
                    $on = (max(abs($dy), abs($dx)) !== 1);   // outer ring + centre on, ring-1 off
                    $m[$y][$x] = $on ? 1 : 0;
                }
            }
        }
    }

    // Dark module + reserve format-info areas.
    $fn[$size - 8][8] = true; $m[$size - 8][8] = 1;
    for ($i = 0; $i < 9; $i++) { if (!$fn[8][$i]) $fn[8][$i] = true; if (!$fn[$i][8]) $fn[$i][8] = true; }
    for ($i = 0; $i < 8; $i++) { $fn[8][$size - 1 - $i] = true; $fn[$size - 1 - $i][8] = true; }

    // Reserve version-info areas (v>=7).
    if ($version >= 7) {
        for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) {
            $fn[$size - 11 + $j][$i] = true;
            $fn[$i][$size - 11 + $j] = true;
        }
    }

    // Data placement — zigzag from bottom-right, skipping the vertical timing column.
    $bitStr = '';
    foreach ($codewords as $cw) $bitStr .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    $bitLen = strlen($bitStr); $bi = 0;
    $col = $size - 1;
    $upward = true;
    while ($col > 0) {
        if ($col === 6) $col--;   // skip timing column
        for ($k = 0; $k < $size; $k++) {
            $row = $upward ? ($size - 1 - $k) : $k;
            for ($c = 0; $c < 2; $c++) {
                $x = $col - $c;
                if ($fn[$row][$x]) continue;
                $bit = ($bi < $bitLen) ? (int) $bitStr[$bi] : 0; $bi++;
                // Apply mask.
                if (lmeg_qr_mask($mask, $row, $x)) $bit ^= 1;
                $m[$row][$x] = $bit;
            }
        }
        $col -= 2; $upward = !$upward;
    }

    // Format info (ECC level L = 01) + mask.
    lmeg_qr_place_format($m, $size, $mask);
    if ($version >= 7) lmeg_qr_place_version($m, $size, $version);

    return ['size' => $size, 'rows' => $m];
}

function lmeg_qr_mask($mask, $row, $col) {
    switch ($mask) {
        case 0: return ($row + $col) % 2 === 0;
        case 1: return $row % 2 === 0;
        case 2: return $col % 3 === 0;
        case 3: return ($row + $col) % 3 === 0;
        case 4: return ((intdiv($row, 2)) + (intdiv($col, 3))) % 2 === 0;
        case 5: return (($row * $col) % 2) + (($row * $col) % 3) === 0;
        case 6: return ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0;
        case 7: return ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0;
    }
    return false;
}

/** BCH(15,5) format string for level L + mask, XOR-masked per spec. */
function lmeg_qr_format_bits($mask) {
    $data = (0b01 << 3) | $mask;   // level L = 01
    $v = $data << 10;
    $g = 0b10100110111;
    for ($i = 14; $i >= 10; $i--) if (($v >> $i) & 1) $v ^= $g << ($i - 10);
    return (($data << 10) | $v) ^ 0b101010000010010;
}

function lmeg_qr_place_format(&$m, $size, $mask) {
    $bits = lmeg_qr_format_bits($mask);   // bit 0 = LSB
    for ($i = 0; $i < 15; $i++) {
        $mod = ($bits >> $i) & 1;
        // Vertical copy (column 8): rows 0-5, skip timing at 6, then bottom edge.
        if ($i < 6)      $m[$i][8]            = $mod;
        elseif ($i < 8)  $m[$i + 1][8]        = $mod;
        else             $m[$size - 15 + $i][8] = $mod;
        // Horizontal copy (row 8): right edge, skip timing at 6, then left edge.
        if ($i < 8)      $m[8][$size - $i - 1] = $mod;
        elseif ($i < 9)  $m[8][15 - $i]        = $mod;
        else             $m[8][15 - $i - 1]    = $mod;
    }
    $m[$size - 8][8] = 1;   // fixed dark module
}

/** BCH(18,6) version info for v>=7. */
function lmeg_qr_version_bits($version) {
    $v = $version << 12;
    $g = 0b1111100100101;
    for ($i = 17; $i >= 12; $i--) if (($v >> $i) & 1) $v ^= $g << ($i - 12);
    return ($version << 12) | $v;
}

function lmeg_qr_place_version(&$m, $size, $version) {
    $bits = lmeg_qr_version_bits($version);
    for ($i = 0; $i < 18; $i++) {
        $b = ($bits >> $i) & 1;
        $r = intdiv($i, 3); $c = $i % 3;
        $m[$r][$size - 11 + $c] = $b;
        $m[$size - 11 + $c][$r] = $b;
    }
}

/* ---- penalty scoring + public API ---------------------------------------- */

function lmeg_qr_penalty($rows, $size) {
    $score = 0;
    // Rule 1: runs of 5+ same-colour in row/col.
    for ($y = 0; $y < $size; $y++) {
        for ($o = 0; $o < 2; $o++) {
            $run = 1; $prev = -1;
            for ($x = 0; $x < $size; $x++) {
                $val = $o === 0 ? $rows[$y][$x] : $rows[$x][$y];
                if ($val === $prev) { $run++; if ($run === 5) $score += 3; elseif ($run > 5) $score++; }
                else { $run = 1; $prev = $val; }
            }
        }
    }
    // Rule 2: 2x2 blocks.
    for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {
        $v = $rows[$y][$x];
        if ($v === $rows[$y][$x + 1] && $v === $rows[$y + 1][$x] && $v === $rows[$y + 1][$x + 1]) $score += 3;
    }
    // Rule 3: finder-like 1:1:3:1:1 patterns.
    $pat1 = [1,0,1,1,1,0,1,0,0,0,0]; $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x <= $size - 11; $x++) {
        $h = []; $vv = [];
        for ($k = 0; $k < 11; $k++) { $h[] = $rows[$y][$x + $k]; $vv[] = $rows[$x + $k][$y]; }
        if ($h === $pat1 || $h === $pat2) $score += 40;
        if ($vv === $pat1 || $vv === $pat2) $score += 40;
    }
    // Rule 4: dark-module balance.
    $dark = 0; foreach ($rows as $r) $dark += array_sum($r);
    $total = $size * $size;
    $ratio = ($dark * 100) / $total;
    $score += (int) ((abs($ratio - 50) / 5)) * 10;
    return $score;
}

/** Full matrix for $text (chooses the best of 8 masks). Returns ['size','rows'] or false. */
function lmeg_qr_matrix($text) {
    $enc = lmeg_qr_encode_codewords((string) $text);
    if (!$enc) return false;
    $best = null; $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $cand = lmeg_qr_build_matrix($enc['version'], $enc['codewords'], $mask);
        $p = lmeg_qr_penalty($cand['rows'], $cand['size']);
        if ($p < $bestScore) { $bestScore = $p; $best = $cand; }
    }
    return $best;
}

/** Inline SVG QR. $args: size (px, default 220), quiet (modules, default 4), dark/light colours. */
function lmeg_qr_svg($text, $args = []) {
    $mx = lmeg_qr_matrix($text);
    if (!$mx) return '';
    $n = $mx['size'];
    $quiet = isset($args['quiet']) ? (int) $args['quiet'] : 4;
    $px    = isset($args['size'])  ? (int) $args['size']  : 220;
    $dark  = $args['dark']  ?? '#000000';
    $light = $args['light'] ?? '#ffffff';
    $total = $n + 2 * $quiet;
    $rects = '';
    for ($y = 0; $y < $n; $y++) {
        $x = 0;
        while ($x < $n) {
            if ($mx['rows'][$y][$x]) {
                $run = 1;
                while ($x + $run < $n && $mx['rows'][$y][$x + $run]) $run++;   // merge horizontal runs
                $rects .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="' . $run . '" height="1"/>';
                $x += $run;
            } else { $x++; }
        }
    }
    $bg = ($light === 'none') ? '' : '<rect width="' . $total . '" height="' . $total . '" fill="' . $light . '"/>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $px . '" height="' . $px . '" viewBox="0 0 ' . $total . ' ' . $total . '" '
        . 'shape-rendering="crispEdges" role="img" aria-label="QR code">' . $bg
        . '<g fill="' . $dark . '">' . $rects . '</g></svg>';
}
