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
 * SVN : $URL$
 * SVN : $Id$
 *
 */

if (!isset($oreon))
    exit();

    if (!$oreon->user->admin) {
        if ($sg_id && false === strpos($sgString, "'".$sg_id."'")) {
            $msg = new CentreonMsg();
            $msg->setImage("./img/icones/16x16/warning.gif");
            $msg->setTextStyle("bold");
            $msg->setText(_('You are not allowed to access this service group'));
            return null;
        }
    }


/*
 * Database retrieve information for differents elements list we need on the page
 *
 * Services comes from DB -> Store in $hServices Array and $hgServices
 */
$hgServices = array();

$aclFrom = "";
$aclCond = "";
if (!$oreon->user->admin) {
    $aclFrom = ", hostgroup_relation hgr, $aclDbName.centreon_acl acl ";
    $aclCond = " AND hg.hg_id = hgr.hostgroup_hg_id
        AND hgr.host_host_id = acl.host_id
        AND acl.service_id = sv.service_id
        AND acl.group_id IN (".$acl->getAccessGroupsString().") ";
}

$DBRESULT = $pearDB->query( "SELECT DISTINCT hg.hg_name, hg.hg_id, sv.service_description, sv.service_template_model_stm_id, sv.service_id " .
        "FROM host_service_relation hsr, service sv, hostgroup hg $aclFrom" .
        "WHERE sv.service_register = '1' " .
        "AND hsr.service_service_id = sv.service_id " .
        "AND hg.hg_id = hsr.hostgroup_hg_id " . $aclCond .
        "ORDER BY hg.hg_name, sv.service_description");
while ($elem = $DBRESULT->fetchRow())   {
// If the description of our Service is in the Template definition, we have to catch it, whatever the level of it :-)
    if (!$elem["service_description"]) {
        $elem["service_description"] = getMyServiceName($elem['service_template_model_stm_id']);
    }

    $elem["service_description"] = str_replace("#S#", "/", $elem["service_description"]);
    $elem["service_description"] = str_replace("#BS#", "\\", $elem["service_description"]);

    $hgServices[$elem["hg_id"] . '-'.$elem["service_id"]] = $elem["hg_name"]."&nbsp;&nbsp;&nbsp;&nbsp;".$elem["service_description"];
}
$DBRESULT->free();

$initialValues = array();

/*
 * Database retrieve information for ServiceGroup
 */
$sg = array();
$hServices = array();
if (($o == "c" || $o == "w") && $sg_id)	{
    $DBRESULT = $pearDB->query("SELECT * FROM servicegroup WHERE sg_id = '".$sg_id."' LIMIT 1");

    // Set base value
    $sg = array_map("myDecode", $DBRESULT->fetchRow());

    // Set ServiceGroup Childs
    if (!$oreon->user->admin) {
        $aclSql = "SELECT sgr.host_host_id, sgr.service_service_id
            FROM servicegroup_relation sgr, $aclDbName.centreon_acl acl
            WHERE sgr.servicegroup_sg_id = '".$sg_id."'
                  AND sgr.host_host_id = acl.host_id
                      AND acl.service_id = sgr.service_service_id
                      AND acl.group_id IN (".$acl->getAccessGroupsString().")";
        $aclRes = $pearDB->query($aclSql);
        $aclHs = array();
        while ($aclRow = $aclRes->fetchRow()) {
            $aclHs[$aclRow['host_host_id']."-".$aclRow['service_service_id']] = true;
        }
    }
    $DBRESULT = $pearDB->query("SELECT host_host_id, service_service_id
            FROM servicegroup_relation
            WHERE servicegroup_sg_id = '".$sg_id."'
            AND host_host_id IS NOT NULL
            ORDER BY service_service_id");
    for ($i = 0; $host = $DBRESULT->fetchRow(); $i++) {
        $hkey = $host["host_host_id"]."-".$host["service_service_id"];
        if (isset($aclHs) && !isset($aclHs[$hkey])) {
            $initialValues['sg_hServices'][] = $hkey;
        } else {
            $sg["sg_hServices"][$i] = $hkey;
        }
    }
    $DBRESULT->free();

    $DBRESULT = $pearDB->query("SELECT hostgroup_hg_id, service_service_id
            FROM servicegroup_relation
            WHERE servicegroup_sg_id = '".$sg_id."'
            AND hostgroup_hg_id IS NOT NULL
            ORDER BY service_service_id");
    for ($i = 0; $services = $DBRESULT->fetchRow(); $i++) {
        $hgkey = $services["hostgroup_hg_id"]."-".$services["service_service_id"];
        if (!$oreon->user->admin && !isset($hgServices[$hgkey])) {
            $initialValues['sg_hgServices'][] = $hgkey;
        } else {
            $sg["sg_hgServices"][$i] = $hgkey;
        }
    }
    $DBRESULT->free();

    $query = "SELECT host_id, host_name, service_id, service_description
        FROM service s, servicegroup_relation sgr, host h
        WHERE s.service_id = sgr.service_service_id
        AND sgr.host_host_id = h.host_id
        AND sgr.servicegroup_sg_id = "  . $sg_id;
    $res = $pearDB->query($query);
    while ($row = $res->fetchRow()) {
        $row['service_description'] = str_replace("#S#", "/", $row['service_description']);
        $hServices[$row["host_id"]."-".$row['service_id']] = $row["host_name"]."&nbsp;-&nbsp;".$row['service_description'];
    }
}

#
# End of "database-retrieved" information
##########################################################
##########################################################
# Var information to format the element
#
$attrsText 		= array("size"=>"30");
$attrsAdvSelect = array("style" => "width: 400px; height: 250px;");
$attrsTextarea 	= array("rows"=>"5", "cols"=>"40");
$eTemplate	= '<table><tr><td><div class="ams">{label_2}</div>{unselected}</td><td align="center">{add}<br /><br /><br />{remove}</td><td><div class="ams">{label_3}</div>{selected}</td></tr></table>';

#
## Form begin
#
$form = new HTML_QuickForm('Form', 'post', "?p=".$p);
if ($o == "a")
$form->addElement('header', 'title', _("Add a Service Group"));
else if ($o == "c")
$form->addElement('header', 'title', _("Modify a Service Group"));
else if ($o == "w")
$form->addElement('header', 'title', _("View a Service Group"));

#
## Contact basic information
#
$form->addElement('header', 'information', _("General Information"));
$form->addElement('text', 'sg_name', _("Service Group Name"), $attrsText);
$form->addElement('text', 'sg_alias', _("Description"), $attrsText);

##
## Services Selection
##
$hostFilter = array(null => null,
        0    => sprintf('__%s__', _('ALL')));
$hostFilter = ($hostFilter + $acl->getHostAclConf(null,
                                                 $oreon->broker->getBroker(),
                                                 array('fields'  => array('host.host_id', 'host.host_name'),
                                                       'keys'    => array('host_id'),
                                                       'get_row' => 'host_name',
                                                       'order'   => array('host.host_name')),
                                                 true));

$form->addElement('select', 'host_filter', _('Host'), $hostFilter, array('onChange' => 'hostFilterSelect(this);'));
$form->addElement('header', 'relation', _("Relations"));
$ams1 = $form->addElement('advmultiselect', 'sg_hServices', array(_("Linked Host Services"), _("Available"), _("Selected")), $hServices, $attrsAdvSelect, SORT_ASC);
$ams1->setButtonAttributes('add', array('value' =>  _("Add")));
$ams1->setButtonAttributes('remove', array('value' => _("Remove")));
$ams1->setElementTemplate($eTemplate);
echo $ams1->getElementJs(false);

$form->addElement('header', 'relation', _("Relations"));
$ams1 = $form->addElement('advmultiselect', 'sg_hgServices', array(_("Linked Host Group Services"), _("Available"), _("Selected")), $hgServices, $attrsAdvSelect, SORT_ASC);
$ams1->setButtonAttributes('add', array('value' =>  _("Add")));
$ams1->setButtonAttributes('remove', array('value' => _("Remove")));
$ams1->setElementTemplate($eTemplate);

/*
 * Further informations
 */
$form->addElement('header', 'furtherInfos', _("Additional Information"));
$sgActivation[] = HTML_QuickForm::createElement('radio', 'sg_activate', null, _("Enabled"), '1');
$sgActivation[] = HTML_QuickForm::createElement('radio', 'sg_activate', null, _("Disabled"), '0');
$form->addGroup($sgActivation, 'sg_activate', _("Status"), '&nbsp;');
$form->setDefaults(array('sg_activate' => '1'));
$form->addElement('textarea', 'sg_comment', _("Comments"), $attrsTextarea);

$tab = array();
$tab[] = HTML_QuickForm::createElement('radio', 'action', null, _("List"), '1');
$tab[] = HTML_QuickForm::createElement('radio', 'action', null, _("Form"), '0');
$form->addGroup($tab, 'action', _("Post Validation"), '&nbsp;');
$form->setDefaults(array('action' => '1'));

$form->addElement('hidden', 'sg_id');
$redirect = $form->addElement('hidden', 'o');
$redirect->setValue($o);

$init = $form->addElement('hidden', 'initialValues');
$init->setValue(serialize($initialValues));

/*
 * Form Rules
 */
function myReplace()	{
    global $form;
    $ret = $form->getSubmitValues();
    return (str_replace(" ", "_", $ret["sg_name"]));
}
$form->applyFilter('__ALL__', 'myTrim');
$form->applyFilter('sg_name', 'myReplace');
$form->addRule('sg_name', _("Compulsory Name"), 'required');
$form->addRule('sg_alias', _("Compulsory Description"), 'required');
$form->registerRule('exist', 'callback', 'testServiceGroupExistence');
$form->addRule('sg_name', _("Name is already in use"), 'exist');
$form->setRequiredNote("<font style='color: red;'>*</font>&nbsp;". _("Required fields"));

#
##End of form definition
#

# Smarty template Init
$tpl = new Smarty();
$tpl = initSmartyTpl($path, $tpl);

# Just watch a Service Group information
if ($o == "w")	{
    if ($centreon->user->access->page($p) != 2)
        $form->addElement("button", "change", _("Modify"), array("onClick"=>"javascript:window.location.href='?p=".$p."&o=c&sg_id=".$sg_id."'"));
    $form->setDefaults($sg);
    $form->freeze();
}
# Modify a Service Group information
else if ($o == "c")	{
    $subC = $form->addElement('submit', 'submitC', _("Save"));
    $res = $form->addElement('reset', 'reset', _("Reset"));
    $form->setDefaults($sg);
}
# Add a Service Group information
else if ($o == "a")	{
    $subA = $form->addElement('submit', 'submitA', _("Save"));
    $res = $form->addElement('reset', 'reset', _("Reset"));
}

$tpl->assign('nagios', $oreon->user->get_version());
$tpl->assign("helpattr", 'TITLE, "'._("Help").'", CLOSEBTN, true, FIX, [this, 0, 5], BGCOLOR, "#ffff99", BORDERCOLOR, "orange", TITLEFONTCOLOR, "black", TITLEBGCOLOR, "orange", CLOSEBTNCOLORS, ["","black", "white", "red"], WIDTH, -300, SHADOW, true, TEXTALIGN, "justify"' );

# prepare help texts
$helptext = "";
include_once("help.php");
foreach ($help as $key => $text) {
    $helptext .= '<span style="display:none" id="help:'.$key.'">'.$text.'</span>'."\n";
}
$tpl->assign("helptext", $helptext);

$valid = false;
if ($form->validate())	{
    $sgObj = $form->getElement('sg_id');
    if ($form->getSubmitValue("submitA"))
        $sgObj->setValue(insertServiceGroupInDB());
    else if ($form->getSubmitValue("submitC"))
        updateServiceGroupInDB($sgObj->getValue());
    $o = NULL;
    $form->addElement("button", "change", _("Modify"), array("onClick"=>"javascript:window.location.href='?p=".$p."&o=c&sg_id=".$sgObj->getValue()."'"));
    $form->freeze();
    $valid = true;
}
$action = $form->getSubmitValue("action");

if ($valid && $action["action"]["action"])
require_once($path."listServiceGroup.php");
else	{
#Apply a template definition
    $renderer = new HTML_QuickForm_Renderer_ArraySmarty($tpl, true);
    $renderer->setRequiredTemplate('{$label}&nbsp;<font color="red" size="1">*</font>');
    $renderer->setErrorTemplate('<font color="red">{$error}</font><br />{$html}');
    $form->accept($renderer);
    $tpl->assign('form', $renderer->toArray());
    $tpl->assign('o', $o);
    $tpl->display("formServiceGroup.ihtml");
}
?>
<script type='text/javascript'>
function hostFilterSelect(elem)
{
    var arg = 'host_id='+elem.value;

    if (window.XMLHttpRequest) {
        var xhr = new XMLHttpRequest();
    } else if(window.ActiveXObject){
        try {
            var xhr = new ActiveXObject("Msxml2.XMLHTTP");
        } catch (e) {
            var xhr = new ActiveXObject("Microsoft.XMLHTTP");
        }
    } else {
        var xhr = false;
    }

    xhr.open("POST","./include/configuration/configObject/servicegroup/getServiceXml.php", true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    xhr.send(arg);

    xhr.onreadystatechange = function()
    {
        if (xhr && xhr.readyState == 4 && xhr.status == 200 && xhr.responseXML){
            var response = xhr.responseXML.documentElement;
            var _services = response.getElementsByTagName("services");
            var _selbox;

            if (document.getElementById("sg_hServices-f")) {
                _selbox = document.getElementById("sg_hServices-f");
                _selected = document.getElementById("sg_hServices-t");
            } else if (document.getElementById("__sg_hServices")) {
                _selbox = document.getElementById("__sg_hServices");
                _selected = document.getElementById("_sg_hServices");
            }

            while ( _selbox.options.length > 0 ){
                _selbox.options[0] = null;
            }

            if (_services.length == 0) {
                _selbox.setAttribute('disabled', 'disabled');
            } else {
                _selbox.removeAttribute('disabled');
            }

            for (var i = 0 ; i < _services.length ; i++) {
                var _svc 		 = _services[i];
                var _id 		 = _svc.getElementsByTagName("id")[0].firstChild.nodeValue;
                var _description = _svc.getElementsByTagName("description")[0].firstChild.nodeValue;
                var validFlag = true;

                for (var j = 0; j < _selected.length; j++) {
                    if (_id == _selected.options[j].value) {
                        validFlag = false;
                    }
                }

                if (validFlag == true) {
                    new_elem = new Option(_description,_id);
                    _selbox.options[_selbox.length] = new_elem;
                }
            }
        }
    }
}
</script>
