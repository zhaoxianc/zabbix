<?php
/*
** Zabbix
** Copyright (C) 2001-2017 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerWidgetGraphView extends CController {

	protected function init() {
		$this->disableSIDValidation();
	}

	protected function checkInput() {
		$fields = [
			'name' =>				'string',
			'uniqueid' =>			'required|string',
			'initial_load' =>		'in 0,1',
			'edit_mode' =>			'in 0,1',
			'dashboardid' =>		'db dashboard.dashboardid',
			'fields' =>				'required|array',
			'dynamic_hostid' =>		'db hosts.hostid'
		];

		$ret = $this->validateInput($fields);

		if ($ret) {
			/*
			 * @var array  $fields
			 * @var int    $fields['source_type']		  in ZBX_WIDGET_FIELD_RESOURCE_GRAPH, ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH
			 * @var id     $fields['itemid']			  required if $fields['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH
			 * @var id     $fields['graphid']			  required if $fields['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_GRAPH
			 * @var int    $fields['dynamic']             (optional)
			 */
			// TODO VM: if fields are present, check that fields have enough data
		}

		if (!$ret) {
			// TODO VM: prepare propper response for case of incorrect fields
			$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson('')]));
		}

		return $ret;
	}

	protected function checkPermissions() {
		return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
	}

	protected function doAction() {
		// Default values
		$default = [
			'uniqueid' => $this->getInput('uniqueid'),
			'source_type' => ZBX_WIDGET_FIELD_RESOURCE_GRAPH,
			'dynamic' => WIDGET_SIMPLE_ITEM
		];

		$data = $this->getInput('fields');
		$edit_mode = $this->getInput('edit_mode', 0);

		// TODO VM: for testing
		$data['width'] = '500';
		$data['height'] = '150';

		if (array_key_exists('source_type', $data) && $data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH) {
			$default['itemid'] = null;
		}
		else {
			$data['source_type'] = ZBX_WIDGET_FIELD_RESOURCE_GRAPH;
			$default['graphid'] = null;
		}

		// Apply defualt value for data
		foreach ($default as $key => $value) {
			if (!array_key_exists($key, $data)) {
				$data[$key] = $value;
			}
		}

		// TODO VM: (?) By using uniqid() we are adding new graph after each reload. As a result, we will have A LOT of
		//			nonexisting graphs in timeControl.objectList array in gtlc.js
		// TODO VM: (?) By not using uniqueid(), we need to find a way to distinguish cases, when same graph is added
		//			in two widgets. We can't use widgetid, becasue for new widgets, widgetid is empty string => it can be same
		// TODO VM: (?) Other option is to update gtlc.js script, to delete nonexisting entries from timeControl.objectList,
		//			each time, we are adding new one. (together with using uniqueid())
		$dataid = 'graph_'.$data['uniqueid'];
		$containerid = 'graph_container_'.$data['uniqueid'];
		$dynamic_hostid = $this->getInput('dynamic_hostid', 0);
		$dashboardid = $this->getInput('dashboardid', 0);
		$resourceid = null;
		$profileIdx = 'web.dashbrd';
		$profileIdx2 = $dashboardid;
		$update_profile = $dashboardid ? UPDATE_PROFILE_ON : UPDATE_PROFILE_OFF;

		if ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_GRAPH && $data['graphid']) {
			$resource_type = SCREEN_RESOURCE_GRAPH;
			$resourceid = $data['graphid'];
			$graph_dims = getGraphDims($resourceid);
			$graph_dims['graphHeight'] = $data['height'];
			$graph_dims['width'] = $data['width'];
			$graph = getGraphByGraphId($resourceid);
		}
		elseif ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH && $data['itemid']) {
			$resource_type = SCREEN_RESOURCE_SIMPLE_GRAPH;
			$resourceid = $data['itemid'];
			$graph_dims = getGraphDims();
			$graph_dims['graphHeight'] = $data['height'];
			$graph_dims['width'] = $data['width'];
		}
		else {
			$resource_type = null;
			$graph_dims = getGraphDims();
		}

		$timeline = calculateTime([
			'profileIdx' => $profileIdx,
			'profileIdx2' => $profileIdx2,
			'updateProfile' => $update_profile,
			'period' => null,
			'stime' => null
		]);

		$time_control_data = [
			'id' => $dataid,
			'containerid' => $containerid,
			'objDims' => $graph_dims,
			'loadSBox' => 0,
			'loadImage' => 1,
			'periodFixed' => CProfile::get($profileIdx.'.timelinefixed', 1),
			'sliderMaximumTimePeriod' => ZBX_MAX_PERIOD,
			'reloadOnAdd' => 1
		];

		// data for flickerscreen
		$fs_data = [
			'id' => $dataid,
			'interval' => CWebUser::getRefresh(),
			'timeline' => $timeline,
			'resourcetype' => $resource_type,
			'profileIdx' => $profileIdx,
			'profileIdx2' => $profileIdx2,
			'updateProfile' => $update_profile,
		];

		// Replace graph item by particular host item if dynamic items are used.
		if ($data['dynamic'] == WIDGET_DYNAMIC_ITEM && $dynamic_hostid && $resourceid) {
			// Find same simple-graph item in selected $dynamic_hostid host.
			if ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH) {
				$new_itemid = get_same_item_for_host($resourceid, $dynamic_hostid);
				$resourceid = !empty($new_itemid) ? $new_itemid : null;
			}
			// Find requested host and change graph details.
			elseif ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_GRAPH) {
				// get host
				$hosts = API::Host()->get([
					'hostids' => $dynamic_hostid,
					'output' => ['hostid', 'name']
				]);
				$host = reset($hosts);

				// get graph
				$graph = API::Graph()->get([
					'graphids' => $resourceid,
					'output' => API_OUTPUT_EXTEND,
					'selectHosts' => ['hostid'],
					'selectGraphItems' => API_OUTPUT_EXTEND
				]);
				$graph = reset($graph);

				// if all items are from one host we change them, or set calculated if not exist on that host
				if ($graph && count($graph['hosts']) == 1) {
					if ($graph['ymax_type'] == GRAPH_YAXIS_TYPE_ITEM_VALUE && $graph['ymax_itemid']) {
						$new_dynamic = getSameGraphItemsForHost(
							[['itemid' => $graph['ymax_itemid']]],
							$dynamic_hostid,
							false
						);
						$new_dynamic = reset($new_dynamic);

						if ($new_dynamic && array_key_exists('itemid', $new_dynamic) && $new_dynamic['itemid'] > 0) {
							$graph['ymax_itemid'] = $new_dynamic['itemid'];
						}
						else {
							$graph['ymax_type'] = GRAPH_YAXIS_TYPE_CALCULATED;
						}
					}

					if ($graph['ymin_type'] == GRAPH_YAXIS_TYPE_ITEM_VALUE && $graph['ymin_itemid']) {
						$new_dynamic = getSameGraphItemsForHost(
							[['itemid' => $graph['ymin_itemid']]],
							$dynamic_hostid,
							false
						);
						$new_dynamic = reset($new_dynamic);

						if ($new_dynamic && array_key_exists('itemid', $new_dynamic) && $new_dynamic['itemid'] > 0) {
							$graph['ymin_itemid'] = $new_dynamic['itemid'];
						}
						else {
							$graph['ymin_type'] = GRAPH_YAXIS_TYPE_CALCULATED;
						}
					}
				}
			}
		}

		// Build graph action and data source links.
		if ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_SIMPLE_GRAPH) {
			if (!$edit_mode) {
				$time_control_data['loadSBox'] = 1;
			}

			if ($resourceid) {
				$graph_src = new CUrl('chart.php');
				$graph_src->setArgument('itemids[]', $resourceid);
				$graph_src->setArgument('width', $data['width']);
				$graph_src->setArgument('height', $data['height']);
			}
			else {
				$graph_src = new CUrl('chart3.php');
			}

			$graph_src->setArgument('period', $timeline['period']);
			$graph_src->setArgument('stime', $timeline['stimeNow']);
		}
		elseif ($data['source_type'] == ZBX_WIDGET_FIELD_RESOURCE_GRAPH) {
			$graph_src = '';

			if ($data['dynamic'] == WIDGET_DYNAMIC_ITEM && $dynamic_hostid && $resourceid) {
				// TODO miks: why chart7 and chart3 are allowed only if dynamic is set?
				$chart_file = ($graph['graphtype'] == GRAPH_TYPE_PIE || $graph['graphtype'] == GRAPH_TYPE_EXPLODED)
					? 'chart7.php'
					: 'chart3.php';

				$graph_src = new CUrl($chart_file);

				foreach ($graph as $name => $value) {
					if ($name === 'width' || $name === 'height') {
						continue;
					}
					$graph_src->setArgument($name, $value);
				}

				$new_graph_items = getSameGraphItemsForHost($graph['gitems'], $dynamic_hostid, false);
				foreach ($new_graph_items as $new_graph_item) {
					unset($new_graph_item['gitemid'], $new_graph_item['graphid']);

					foreach ($new_graph_item as $name => $value) {
						$graph_src->setArgument('items['.$new_graph_item['itemid'].']['.$name.']', $value);
					}
				}

				$graph_src->setArgument('name', $host['name'].NAME_DELIMITER.$graph['name']);
			}

			if ($graph_dims['graphtype'] == GRAPH_TYPE_PIE || $graph_dims['graphtype'] == GRAPH_TYPE_EXPLODED) {
				if ($data['dynamic'] == WIDGET_SIMPLE_ITEM || $graph_src === '') {
					$graph_src = new CUrl('chart6.php');
					$graph_src->setArgument('graphid', $resourceid);
				}

				$timeline['starttime'] = date(TIMESTAMP_FORMAT, get_min_itemclock_by_graphid($resourceid));
			}
			else {
				if ($data['dynamic'] == WIDGET_SIMPLE_ITEM || $graph_src === '') {
					$graph_src = new CUrl('chart2.php');
					$graph_src->setArgument('graphid', $resourceid);
				}

				if (!$edit_mode) {
					$time_control_data['loadSBox'] = 1;
				}
			}

			$graph_src->setArgument('width', $data['width']);
			$graph_src->setArgument('height', $data['height']);
			$graph_src->setArgument('legend', $graph['show_legend']);
			$graph_src->setArgument('period', $timeline['period']);
			$graph_src->setArgument('stime', $timeline['stimeNow']);

			if ($graph_dims['graphtype'] == GRAPH_TYPE_PIE || $graph_dims['graphtype'] == GRAPH_TYPE_EXPLODED) {
				$graph_src->setArgument('graph3d', $graph['show_3d']);
			}
		}

		$graph_src->setArgument('updateProfile', $update_profile);
		$graph_src->setArgument('profileIdx', $profileIdx);
		$graph_src->setArgument('profileIdx2', $profileIdx2);

		$time_control_data['src'] = $graph_src->getUrl();

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', CWidgetConfig::getKnownWidgetTypes()[WIDGET_GRAPH]),
			'graph' => [
				'dataid' => $dataid,
				'containerid' => $containerid,
				'timestamp' => time()
			],
			'widget' => [
				'uniqueid' => $data['uniqueid'],
				'initial_load' => (int) $this->getInput('initial_load', 0),
			],
			'time_control_data' => $time_control_data,
			'timeline' => $timeline,
			'fs_data' => $fs_data,
			'dashboardid' => $dashboardid,
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}
}
