<?php
// $Id: qcp135.class.php 1.1.0 - QCP135 2009-08-15 9:22:20 wishcraft $
//  ------------------------------------------------------------------------ //
//                    CLORA - Chronolabs Australia                           //
//                         Copyright (c) 2009                                //
//                   <http://www.chronolabs.org.au/>                         //
//  ------------------------------------------------------------------------ //
//  This program is free software; you can redistribute it and/or modify     //
//  it under the terms of the SDPL Source Directive Public Licence           //
//  as published by Chronolabs Australia; either version 2 of the License,   //
//  (at your option) any later version.                                      //
//                                                                           //
//  You may not change or alter any portion of this comment or credits       //
//  of supporting developers from this source code or any supporting         //
//  source code which is considered copyrighted (c) material of the          //
//  original comment or credit authors.                                      //
//                                                                           //
//  This program is distributed in the hope that it will be useful,          //
//  but WITHOUT ANY WARRANTY; without even the implied warranty of           //
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            //
//  GNU General Public License for more details.                             //
//                                                                           //
//  You should have received a copy of the GNU General Public License        //
//  along with this program; if not, write to the Chronolab Australia        //
//  Chronolabs International PO BOX 699, DULWICH HILL, NSW, 2203, Australia  //
//  ------------------------------------------------------------------------ //

if (!class_exists('qcp135')) {
    error_reporting(E_ERROR);
    
    class qcp135
    {
        public $base;
        public $enum;
        public $seed;
        public $crc;
            
        public function __construct($data, $seed, $len=29)
        {
            $this->seed = $seed;
            $this->length = $len;
            $this->base = new qcp135_base((int)$seed);
            $this->enum = new qcp135_enumerator($this->base);
            
            if (!empty($data)) {
                for ($i=1; $i<strlen($data); $i++) {
                    $enum_calc = $this->enum->enum_calc(substr($data, $i, 1), $enum_calc);
                }
                $qcp135_crc = new qcp135_leaver($enum_calc, $this->base, $this->length);
                $this->crc = $qcp135_crc->crc;
            }
        }
            
        public function calc($data)
        {
            for ($i=1; $i<strlen($data); $i++) {
                $enum_calc = $this->enum->enum_calc(substr($data, $i, 1), $enum_calc);
            }
            $qcp135_crc = new qcp135_leaver($enum_calc, $this->base, $this->length);
            return $qcp135_crc->crc;
        }
    }
}

require('qcp135.base.php');
require('qcp135.enumerator.php');
require('qcp135.leaver.php');
