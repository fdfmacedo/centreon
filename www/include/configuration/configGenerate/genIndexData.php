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
 * SVN : $URL:$
 * SVN : $Id:$
 *
 */
 
define('DAY_SECS', 86400);
define('NB_REQUEST', 1000);

/* RRD retention cache*/
$retentionCache = array();
$serviceRetention = array();
$retentionRes = $pearDB->query("SELECT host_host_id as id, MAX(hg_rrd_retention) as retention
                                FROM hostgroup, hostgroup_relation
                                WHERE hostgroup.hg_id = hostgroup_relation.hostgroup_hg_id
                                GROUP BY host_host_id");
while ($row = $retentionRes->fetchRow()) {
    if (!isset($retentionCache[$row['id']])) {
        $retentionCache[$row['id']] = $row['retention'] * DAY_SECS;
    }
}

/* Change index data info */
$indexToAdd = array();
$listIndexData = getListIndexData();

$hostSvcSql = "SELECT host_id, service_id, host_name, service_description
FROM host_service_relation hsr, host h, service s
WHERE hsr.host_host_id IS NOT NULL
AND hsr.host_host_id = h.host_id
AND h.host_activate = '1'
AND hsr.service_service_id = s.service_id
UNION
SELECT host_id, service_id, host_name, service_description
FROM host_service_relation hsr, hostgroup_relation hgr, host h, service s
WHERE hsr.hostgroup_hg_id = hgr.hostgroup_hg_id
AND hgr.host_host_id = h.host_id
AND h.host_activate = '1'
AND hsr.service_service_id = s.service_id";
$hostSvcRes = $pearDB->query($hostSvcSql);
$hostSvc = array();
while ($hostSvcRow = $hostSvcRes->fetchRow()) {
    
    $relation = $hostSvcRow['host_id'].';'.$hostSvcRow['service_id'];
    $hostSvc[$hostSvcRow['host_id']][$hostSvcRow['service_id']] = true;
    if (!isset($listIndexData[$relation])) {
        $indexToAdd[] = array('host_id' => $hostSvcRow['host_id'],
                              'service_id' => $hostSvcRow['service_id'],
                              'host_name' => $hostSvcRow['host_name'],
                              'service_description' => $hostSvcRow['service_description']);
    }

    $host_id = $hostSvcRow['host_id'];
    if (isset($retentionCache[$host_id])) {
        $serviceRetention[$retentionCache[$host_id]] = $host_id.';'.$hostSvcRow['service_id'];
    }
}

$res = $pearDBO->query("SELECT host_id, service_id FROM index_data");
$toDelete = "";
$i = 0;
$sql = "UPDATE index_data SET to_delete = 1 "
     . "WHERE CONCAT(host_id,';',service_id) IN (%s)";
while ($row = $res->fetchRow()) {
    $hid = $row['host_id'];
    $sid = $row['service_id'];
    if (!isset($hostSvc[$hid]) || !isset($hostSvc[$hid][$sid])) {
        $toDelete .= "'$hid;$sid',";
    }
    $i++;
    if (($i % NB_REQUEST) == 0) {
        if ($toDelete != "") {
            $pearDBO->query(sprintf($sql, substr($toDelete, 0, (strlen($toDelete)-1))));
            $toDelete = "";
        }
    }
}

if ($toDelete != "") {
    $pearDBO->query(sprintf($sql, substr($toDelete, 0, (strlen($toDelete)-1))));
}
unset($hostSvc);


// 
$queryAddIndex = "INSERT INTO index_data (host_id, host_name, service_id, service_description, to_delete) VALUES ";
$queryAddIndexValues = "(%d, '%s', %d, '%s', 0),";
$valuesToAdd = "";
$i = 0;

if ($toDelete != "") {
    $pearDBO->query(sprintf($sql, substr($toDelete, 0, (strlen($toDelete)-1))));
}
unset($hostSvc);


// 
$queryAddIndex = "INSERT INTO index_data (host_id, host_name, service_id, service_description, to_delete) VALUES ";
$queryAddIndexValues = "(%d, '%s', %d, '%s', 0),";
$valuesToAdd = "";
$i = 0;
foreach ($indexToAdd as $index) {
    $valuesToAdd .= sprintf($queryAddIndexValues, $index['host_id'], $index['host_name'], $index['service_id'], $index['service_description']);
    $i++;
    if (($i % NB_REQUEST) == 0) {
        if ($valuesToAdd != "") {
            $pearDBO->query($queryAddIndex.substr($valuesToAdd, 0, (strlen($valuesToAdd)-1)));
            $valuesToAdd = "";
        }
    }
}

if ($valuesToAdd != "") {
    $pearDBO->query($queryAddIndex.substr($valuesToAdd, 0, (strlen($valuesToAdd)-1)));
}


$pearDBO->query('UPDATE index_data SET rrd_retention = NULL');
foreach ($serviceRetention as $retentionValue => $retention) {
    $combostr = "";
    foreach ($retention as $hostServiceCombo) {
        if ($combostr != "") {
            $combostr .= ", ";
        }
        $combostr .= "'".$pearDBO->escape($hostServiceCombo)."'";
    }
    if ($combostr != "") {
        $pearDBO->query('UPDATE index_data SET rrd_retention = '.$pearDBO->escape($retentionValue).' WHERE CONCAT(host_id, ";", service_id) IN ('.$combostr.')');
    }
}
