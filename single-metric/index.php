<?php

/*
 * Copyright 2005 - 2022 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

require_once "../require.php";
require_once $centreon_path . 'www/class/centreon.class.php';
require_once $centreon_path . 'www/class/centreonSession.class.php';
require_once $centreon_path . 'www/class/centreonWidget.class.php';
require_once $centreon_path . 'www/class/centreonDuration.class.php';
require_once $centreon_path . 'www/class/centreonUtils.class.php';
require_once $centreon_path . 'www/class/centreonACL.class.php';
require_once $centreon_path . 'www/class/centreonHost.class.php';
require_once $centreon_path . 'bootstrap.php';

CentreonSession::start(1);

if (!isset($_SESSION['centreon']) || !isset($_REQUEST['widgetId'])) {
    exit;
}

$centreon = $_SESSION['centreon'];
$widgetId = filter_var($_REQUEST['widgetId'], FILTER_VALIDATE_INT);
$grouplistStr = '';

try {
    if ($widgetId === false) {
        throw new InvalidArgumentException('Widget ID must be an integer');
    }
    $db_centreon = $dependencyInjector['configuration_db'];
    $db = $dependencyInjector['realtime_db'];

    $widgetObj = new CentreonWidget($centreon, $db_centreon);
    $preferences = $widgetObj->getWidgetPreferences($widgetId);
    $autoRefresh = filter_var($preferences['refresh_interval'], FILTER_VALIDATE_INT);
    if ($autoRefresh === false || $autoRefresh < 5) {
        $autoRefresh = 30;
    }
} catch (Exception $e) {
    echo $e->getMessage() . "<br/>";
    exit;
}

$kernel = \App\Kernel::createForWeb();
$resourceController = $kernel->getContainer()->get(
    \Centreon\Application\Controller\MonitoringResourceController::class
);

//configure smarty

if ($centreon->user->admin == 0) {
    $access = new CentreonACL($centreon->user->get_id());
    $grouplist = $access->getAccessGroups();
    $grouplistStr = $access->getAccessGroupsString();
}

$path = $centreon_path . "www/widgets/single-metric/src/";
$template = new Smarty();
$template = initSmartyTplForPopup($path, $template, "./", $centreon_path);

$data = array();

// Functions
function humanReadable($value,$unit,$base) {
    $precision = 2;
    $prefix = array('a', 'f', 'p', 'n', 'u', 'm', '', 'k', 'M', 'G', 'T', 'P', 'E','Z','Y');
    $puissance = min(max(floor(log(abs($value), $base)), -6), 6);
    $new_value = [ round((float)$value / pow($base, $puissance), $precision), $prefix[$puissance + 6] . $unit];
    return($new_value);
}

if ($preferences['service'] == null)
{
  $template->display('metric.ihtml');
} else if ($preferences['service'] == "") {
  $template->display('metric.ihtml');
} else {
  $tabService = explode("-", $preferences['service']);
  $hostid = $tabService[0];
  $serviceid = $tabService[1];

  $query = "SELECT
        i.host_name AS host_name,
        i.service_description AS service_description,
        i.service_id AS service_id,
        i.host_id AS host_id,
        REPLACE(m.current_value,'.',',') AS current_value,
        m.current_value AS current_float_value,
        m.metric_name AS metric_name,
        m.unit_name AS unit_name,
        m.warn AS warning,
        m.crit AS critical,
        s.state AS status
    FROM
        metrics m,
        hosts h "
    . ($centreon->user->admin == 0 ? ", centreon_acl acl " : "")
    . " , index_data i
    LEFT JOIN services s ON s.service_id  = i.service_id AND s.enabled = 1
    WHERE i.service_id = " . $serviceid . "
    AND i.id = m.index_id
    AND m.metric_name = '" . $preferences['metric_name'] . "'
    AND i.host_id = h.host_id 
    AND i.host_id = " . $hostid . " ";
  if ($centreon->user->admin == 0) {
    $query .= "AND i.host_id = acl.host_id
        AND i.service_id = acl.service_id
        AND acl.group_id IN (" . ($grouplistStr != "" ? $grouplistStr : 0) . ")";
  }
  $query .= "AND s.enabled = 1
        AND h.enabled = 1;";

  $numLine = 1;

  $res = $db->query($query);
  while ($row = $res->fetch()) {
    $row['details_uri'] = $resourceController->buildServiceDetailsUri($row['host_id'], $row['service_id']);
    $row['host_uri'] = $resourceController->buildHostDetailsUri($row['host_id']);
    $row['graph_uri'] = $resourceController->buildServiceUri($row['host_id'], $row['service_id'], 'graph');
    $data[] = $row;
    $numLine++;
  }

  /* Calculate Threshold font size */
  $preferences['threshold_font_size'] = round($preferences['font_size'] / 8,0);
  if ( $preferences['threshold_font_size'] < 9 ) { $preferences['threshold_font_size'] = 9; }

  // Human readable
  if ( strcmp($preferences['display_number'], '1000') == 0 or strcmp($preferences['display_number'], '1024') == 0 )
  {
     $new_value = humanReadable($data[0]['current_float_value'], $data[0]['unit_name'], $preferences['display_number']);
     $data[0]['value_displayed'] = str_replace(".",",",$new_value[0]);
     $data[0]['unit_displayed'] = $new_value[1];
     if ( $data[0]['warning'] != '' ) {
       $new_warning = humanReadable($data[0]['warning'], $data[0]['unit_name'], $preferences['display_number']);
       $data[0]['warning_displayed'] = str_replace(".",",",$new_warning[0]);
     }
     if ( $data[0]['critical'] != '' ) {
       $new_critical = humanReadable($data[0]['critical'], $data[0]['unit_name'], $preferences['display_number']);
       $data[0]['critical_displayed'] = str_replace(".",",",$new_critical[0]);
     }
  } else {
     $data[0]['value_displayed'] = $data[0]['current_value'];
     $data[0]['unit_displayed'] =  $data[0]['unit_name'];
     $data[0]['warning_displayed'] = $data[0]['warning'];
     $data[0]['critical_displayed'] = $data[0]['critical'];
  }

  $template->assign('preferences', $preferences);
  $template->assign('widgetId', $widgetId);
  $template->assign('autoRefresh', $autoRefresh);
  $template->assign('data', $data);
  $template->display('metric.ihtml');
}
