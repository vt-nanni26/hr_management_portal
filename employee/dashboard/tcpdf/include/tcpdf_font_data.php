<?php
//============================================================+
// File name   : tcpdf_font_data.php
// Version     : 1.0.001
// Begin       : 2009-05-05
// Last Update : 2012-05-27
// Author      : Nicola Asuni - Tecnick.com LTD - www.tecnick.com - info@tecnick.com
// License     : GNU-LGPL v3 (http://www.gnu.org/copyleft/lesser.html)
// -------------------------------------------------------------------
// Copyright (C) 2009-2012 Nicola Asuni - Tecnick.com LTD
//
// This file is part of TCPDF software library.
//
// TCPDF is free software: you can redistribute it and/or modify it
// under the terms of the GNU Lesser General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// TCPDF is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with TCPDF.  If not, see <http://www.gnu.org/licenses/>.
//
// See LICENSE.TXT file for more information.
// -------------------------------------------------------------------
//
// Description :Font data for TCPDF library.
//
//============================================================+

/**
 * @file
 * This is the font data file for TCPDF library.
 * @package com.tecnick.tcpdf
 * @version 1.0.001
 */

// Prevent direct access
if (!defined('TCPDF_FONTDATA')) {
    exit('TCPDF_FONTDATA constant is not defined');
}

// Core fonts data
$fontdata = array(
    'courier' => array(
        'type' => 'core',
        'cw' => array_fill(0, 256, 600),
        'name' => 'Courier',
    ),
    'helvetica' => array(
        'type' => 'core',
        'cw' => array_fill(0, 256, 556),
        'name' => 'Helvetica',
    ),
    'times' => array(
        'type' => 'core',
        'cw' => array_fill(0, 256, 556),
        'name' => 'Times-Roman',
    ),
    'symbol' => array(
        'type' => 'core',
        'cw' => array_fill(0, 256, 600),
        'name' => 'Symbol',
    ),
    'zapfdingbats' => array(
        'type' => 'core',
        'cw' => array_fill(0, 256, 600),
        'name' => 'ZapfDingbats',
    ),
);

// FreeSans font data (simplified version)
$fontdata['freesans'] = array(
    'type' => 'TrueTypeUnicode',
    'name' => 'FreeSans',
    'desc' => array('Ascent' => 1000, 'Descent' => -300, 'CapHeight' => 1000, 'Flags' => 32, 'FontBBox' => '[-1168 -469 1518 1051]', 'ItalicAngle' => 0, 'StemV' => 70, 'MissingWidth' => 600),
    'up' => -63,
    'ut' => 44,
    'dw' => 600,
    'cw' => array(
        32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 238, 40 => 333, 41 => 333,
        42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556,
        52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584,
        62 => 584, 63 => 611, 64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778,
        82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611, 91 => 333,
        92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556,
        102 => 333, 103 => 611, 104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
        111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
        120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584, 8364 => 556
    ),
    'enc' => '',
    'diff' => '',
    'file' => 'freesans.z',
    'originalsize' => 119020,
);
?>