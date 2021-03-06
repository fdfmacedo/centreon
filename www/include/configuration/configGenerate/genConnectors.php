<?php
/*
 * Copyright 2005-2011 MERETHIS
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give MERETHIS
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of MERETHIS choice, provided that
 * MERETHIS also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */


    if (!isset($oreon))
        exit();

    if (!is_dir($nagiosCFGPath.$tab['id']."/"))
        mkdir($nagiosCFGPath.$tab['id']."/");
    
	$fileHandler = create_file($nagiosCFGPath.$tab['id']."/connectors.cfg", $oreon->user->get_name());

    $str = "";
    if ($tab['monitoring_engine'] == 'CENGINE')
    {
        /* Getting base path for connectors */
        $queryGetPath = 'SELECT centreonconnector_path
            FROM nagios_server
            WHERE id = ' . $tab['id'];
        $res = $pearDB->query($queryGetPath);
        $row = $res->fetchRow();
        if ($row['centreonconnector_path'] != '') {
            $connector_basepath = preg_replace('!/$!', '', $row['centreonconnector_path']);

            require_once $centreon_path . 'www/class/centreonConnector.class.php';
            $connectorObj = new CentreonConnector($pearDB);
            $connectorList = $connectorObj->getList(true, false, 0, true);
            
            /**
             * Define preg arguments
             */
           $slashesOri = array('/#BR#/',
                               '/#T#/',
                               '/#R#/',
                               '/#S#/',
                               '/#BS#/',
                               '/#P#/');
           $slashesRep = array("\\n",
                               "\\t",
                               "\\r",
                               "/",
                               "\\",
                               "|");
            
            foreach($connectorList as $connector)
            {
                $connector['command_line'] = $connector_basepath . '/' . $connector['command_line'];
                $connector['command_line'] = trim(preg_replace($slashesOri, $slashesRep, $connector['command_line']));
                $str .= "define connector{\n";
                $str .= print_line('connector_name', $connector['name']);
                $str .= print_line('connector_line', $connector['command_line']);
                $str .= "}\n\n";
            }
        }
    }
        
    write_in_file($fileHandler, html_entity_decode($str, ENT_QUOTES, "UTF-8"), $nagiosCFGPath.$tab['id']."/connectors.cfg");
    fclose($fileHandler);
    setFileMod($nagiosCFGPath.$tab['id']."/connectors.cfg");

?>
