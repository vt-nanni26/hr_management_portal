<?php
//============================================================+
// File name   : tcpdf_static.php
// Version     : 1.0.000
// Begin       : 2002-08-03
// Last Update : 2014-04-25
// Author      : Nicola Asuni - Tecnick.com LTD - www.tecnick.com - info@tecnick.com
// License     : GNU-LGPL v3 (http://www.gnu.org/copyleft/lesser.html)
// -------------------------------------------------------------------
// Copyright (C) 2002-2014 Nicola Asuni - Tecnick.com LTD
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
// along with TCPDF. If not, see <http://www.gnu.org/licenses/>.
//
// See LICENSE.TXT file for more information.
//============================================================+

/**
 * @file
 * This is a PHP class that contains static methods for the TCPDF class.
 * @package com.tecnick.tcpdf
 * @author Nicola Asuni
 * @version 1.0.000
 */

/**
 * @class TCPDF_STATIC
 * Static methods used by the TCPDF class.
 * @package com.tecnick.tcpdf
 * @brief PHP class for generating PDF documents without requiring external extensions.
 * @version 1.0.000
 * @author Nicola Asuni - info@tecnick.com
 */
class TCPDF_STATIC {
	
	// Add minimal content to avoid errors
	public static function getRandomSeed() {
		return '';
	}
	
	public static function _escape($s) {
		return $s;
	}
	
	public static function _escapeSQ($s) {
		return $s;
	}
}