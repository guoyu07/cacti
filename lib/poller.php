<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2016 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* exec_poll - executes a command and returns its output
   @arg $command - the command to execute
   @returns - the output of $command after execution */
function exec_poll($command) {
	global $config;

	if (function_exists('popen')) {
		if ($config['cacti_server_os'] == 'unix') {
			$fp = popen($command, 'r');
		}else{
			$fp = popen($command, 'rb');
		}

		/* return if the popen command was not successfull */
		if (!is_resource($fp)) {
			cacti_log('WARNING; Problem with POPEN command.', false, 'POLLER');
			return 'U';
		}

		$output = fgets($fp, 8192);

		pclose($fp);
	}else{
		$output = `$command`;
	}

	return $output;
}

/* exec_poll_php - sends a command to the php script server and returns the
     output
   @arg $command - the command to send to the php script server
   @arg $using_proc_function - whether or not this version of php is making use
     of the proc_open() and proc_close() functions (php 4.3+)
   @arg $pipes - the array of r/w pipes returned from proc_open()
   @arg $proc_fd - the file descriptor returned from proc_open()
   @returns - the output of $command after execution against the php script
     server */
function exec_poll_php($command, $using_proc_function, $pipes, $proc_fd) {
	global $config;
	/* execute using php process */
	if ($using_proc_function == 1) {
		if (is_resource($proc_fd)) {
			/* $pipes now looks like this:
			 * 0 => writeable handle connected to child stdin
			 * 1 => readable handle connected to child stdout
			 * 2 => any error output will be sent to child stderr */

			/* send command to the php server */
			fwrite($pipes[0], $command . "\r\n");

			$output = fgets($pipes[1], 8192);

			if (substr_count($output, 'ERROR') > 0) {
				$output = 'U';
			}
		}
	/* execute the old fashion way */
	}else{
		/* formulate command */
		$command = read_config_option('path_php_binary') . ' ' . $command;

		if (function_exists('popen')) {
			if ($config['cacti_server_os'] == 'unix')  {
				$fp = popen($command, 'r');
			}else{
				$fp = popen($command, 'rb');
			}

			/* return if the popen command was not successfull */
			if (!is_resource($fp)) {
				cacti_log('WARNING; Problem with POPEN command.', false, 'POLLER');
				return 'U';
			}

			$output = fgets($fp, 8192);

			pclose($fp);
		}else{
			$output = `$command`;
		}
	}

	return $output;
}

/* exec_background - executes a program in the background so that php can continue
     to execute code in the foreground
   @arg $filename - the full pathname to the script to execute
   @arg $args - any additional arguments that must be passed onto the executable */
function exec_background($filename, $args = '') {
	global $config, $debug;

	cacti_log("DEBUG: About to Spawn a Remote Process [CMD: $filename, ARGS: $args]", true, 'POLLER', ($debug ? POLLER_VERBOSITY_NONE:POLLER_VERBOSITY_DEBUG));

	if (file_exists($filename)) {
		if ($config['cacti_server_os'] == 'win32') {
			pclose(popen("start \"Cactiplus\" /I \"" . $filename . "\" " . $args, 'r'));
		}else{
			exec($filename . ' ' . $args . ' > /dev/null &');
		}
	}elseif (file_exists_2gb($filename)) {
		exec($filename . ' ' . $args . ' > /dev/null &');
	}
}

/* file_exists_2gb - fail safe version of the file exists function to correct
     for errors in certain versions of php.
   @arg $filename - the name of the file to be tested. */
function file_exists_2gb($filename) {
	global $config;

	$rval = 0;
	if ($config['cacti_server_os'] != 'win32') {
		system("test -f $filename", $rval);
		return ($rval == 0);
	}else{
		return 0;
	}
}

/* update_reindex_cache - builds a cache that is used by the poller to determine if the
     indexes for a particular data query/host have changed
   @arg $host_id - the id of the host to which the data query belongs
   @arg $data_query_id - the id of the data query to rebuild the reindex cache for */
function update_reindex_cache($host_id, $data_query_id) {
	global $config;

	include_once($config['library_path'] . '/data_query.php');
	include_once($config['library_path'] . '/snmp.php');

	/* will be used to keep track of sql statements to execute later on */
	$recache_stack = array();

	$host       = db_fetch_row_prepared('SELECT ' . SQL_NO_CACHE . ' * FROM host WHERE id = ?', array($host_id));
	$data_query = db_fetch_row_prepared('SELECT ' . SQL_NO_CACHE . ' * FROM host_snmp_query WHERE host_id = ? AND snmp_query_id = ?', array($host_id, $data_query_id));

	$data_query_type = db_fetch_cell_prepared('SELECT ' . SQL_NO_CACHE . ' data_input.type_id 
		FROM data_input
		INNER JOIN snmp_query
		ON data_input.id = snmp_query.data_input_id
		WHERE snmp_query.id = ?', 
		array($data_query_id));

	$data_query_xml  = get_data_query_array($data_query_id);

	switch ($data_query['reindex_method']) {
		case DATA_QUERY_AUTOINDEX_NONE:
			break;
		case DATA_QUERY_AUTOINDEX_BACKWARDS_UPTIME:
			/* the uptime backwards method requires snmp, so make sure snmp is actually enabled
			 * on this device first */
			if ($host['snmp_version'] > 0) {
				if (isset($data_query_xml['oid_uptime'])) {
					$oid_uptime = $data_query_xml['oid_uptime'];
				}elseif (isset($data_query_xml['uptime_oid'])) {
					$oid_uptime = $data_query_xml['uptime_oid'];
				}else{
					$oid_uptime = '.1.3.6.1.2.1.1.3.0';
				}

				$session = cacti_snmp_session($host['hostname'], $host['snmp_community'], $host['snmp_version'],
					$host['snmp_username'], $host['snmp_password'], $host['snmp_auth_protocol'], $host['snmp_priv_passphrase'],
					$host['snmp_priv_protocol'], $host['snmp_context'], $host['snmp_engine_id'], $host['snmp_port'],
					$host['snmp_timeout'], $host['ping_retries'], $host['max_oids']);

				if ($session !== false) {
					$assert_value = cacti_snmp_session_get($session, $oid_uptime);
				}

				$session->close();

				$recache_stack[] = "('$host_id', '$data_query_id'," .  POLLER_ACTION_SNMP . ", '<', '$assert_value', '$oid_uptime', '1')";
			}

			break;
		case DATA_QUERY_AUTOINDEX_INDEX_NUM_CHANGE:
			/* this method requires that some command/oid can be used to determine the
			 * current number of indexes in the data query
			 * pay ATTENTION to quoting!
			 * the script parameters are usually enclosed in single tics: '
			 * so we have to enclose the whole list of parameters in double tics: "
			 * */

			/* the assert_value counts the number of distinct indexes currently available in host_snmp_cache
			 * we do NOT make use of <oid_num_indexes> or the like!
			 * this works, even if no <oid_num_indexes> was given
			 */
			$assert_value = sizeof(db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' snmp_index 
				FROM host_snmp_cache 
				WHERE host_id = ? 
				AND snmp_query_id = ? 
				GROUP BY snmp_index', 
				array($host_id, $data_query_id)));

			/* now, we have to build the (list of) commands that are later used on a recache event
			 * the result of those commands will be compared to the assert_value we have just computed
			 * on a comparison failure, a reindex event will be generated
			 */
			switch ($data_query_type) {
				case DATA_INPUT_TYPE_SNMP_QUERY:
					if (isset($data_query_xml['oid_num_indexes'])) { /* we have a specific OID for counting indexes */
						$recache_stack[] = "($host_id, $data_query_id," .  POLLER_ACTION_SNMP . ", '=', '$assert_value', '" . $data_query_xml['oid_num_indexes'] . "', '1')";
					} else { /* count all indexes found */
						$recache_stack[] = "($host_id, $data_query_id," .  POLLER_ACTION_SNMP_COUNT . ", '=', '$assert_value', '" . $data_query_xml["oid_index"] . "', '1')";
					}
					break;
				case DATA_INPUT_TYPE_SCRIPT_QUERY:
					if (isset($data_query_xml['arg_num_indexes'])) { /* we have a specific request for counting indexes */
						/* escape path (windows!) and parameters for use with database sql; TODO: replace by db specific escape function like mysql_real_escape_string? */
						$recache_stack[] = "($host_id, $data_query_id," . POLLER_ACTION_SCRIPT . ", '=', " . db_qstr($assert_value) . ", " . db_qstr(get_script_query_path((isset($data_query_xml['arg_prepend']) ? $data_query_xml['arg_prepend'] . ' ': '') . $data_query_xml['arg_num_indexes'], $data_query_xml['script_path'], $host_id)) . ", '1')";
					} else { /* count all indexes found */
						/* escape path (windows!) and parameters for use with database sql; TODO: replace by db specific escape function like mysql_real_escape_string? */
						$recache_stack[] = "($host_id, $data_query_id," . POLLER_ACTION_SCRIPT_COUNT . ", '=', " . db_qstr($assert_value) . ", " . db_qstr(get_script_query_path((isset($data_query_xml['arg_prepend']) ? $data_query_xml['arg_prepend'] . ' ': '') . $data_query_xml['arg_index'], $data_query_xml['script_path'], $host_id)) . ", '1')";
					}
					break;
				case DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER:
					if (isset($data_query_xml['arg_num_indexes'])) { /* we have a specific request for counting indexes */
						/* escape path (windows!) and parameters for use with database sql; TODO: replace by db specific escape function like mysql_real_escape_string? */
						$recache_stack[] = "($host_id, $data_query_id," . POLLER_ACTION_SCRIPT_PHP . ", '=', " . db_qstr($assert_value) . ", " . db_qstr(get_script_query_path($data_query_xml['script_function'] . ' ' . (isset($data_query_xml['arg_prepend']) ? $data_query_xml['arg_prepend'] . ' ': '') . $data_query_xml['arg_num_indexes'], $data_query_xml['script_path'], $host_id)) . ", '1')";
					} else { /* count all indexes found */
						# TODO: push the correct assert value
						/* escape path (windows!) and parameters for use with database sql; TODO: replace by db specific escape function like mysql_real_escape_string? */
						#$recache_stack[] = "($host_id, $data_query_id," . POLLER_ACTION_SCRIPT_PHP_COUNT . ", '=', " . db_qstr($assert_value) . ", " . db_qstr(get_script_query_path($data_query_xml['script_function'] . ' ' . (isset($data_query_xml['arg_prepend']) ? $data_query_xml['arg_prepend'] . ' ': '') . $data_query_xml['arg_index'], $data_query_xml['script_path'], $host_id)) . ", '1')";
						# omit the assert value until we are able to run an 'index' command through script server
					}
					break;
			}

			break;
		case DATA_QUERY_AUTOINDEX_FIELD_VERIFICATION:
			$primary_indexes = db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . ' snmp_index, oid, field_value 
				FROM host_snmp_cache 
				WHERE host_id = ? 
				AND snmp_query_id = ? 
				AND field_name = ?', 
				array($host_id, $data_query_id, $data_query['sort_field']));

			if (sizeof($primary_indexes) > 0) {
				foreach ($primary_indexes as $index) {
					$assert_value = $index['field_value'];

					if ($data_query_type == DATA_INPUT_TYPE_SNMP_QUERY) {
						$recache_stack[] = "($host_id, $data_query_id," .  POLLER_ACTION_SNMP . ", '=', '$assert_value', '" . $data_query_xml['fields']{$data_query['sort_field']}['oid'] . '.' . $index['snmp_index'] . "', '1')";
					}else if ($data_query_type == DATA_INPUT_TYPE_SCRIPT_QUERY) {
						$recache_stack[] = "('$host_id', '$data_query_id'," . POLLER_ACTION_SCRIPT . ", '=', '$assert_value', '" . get_script_query_path((isset($data_query_xml['arg_prepend']) ? $data_query_xml['arg_prepend'] . ' ': '') . $data_query_xml['arg_get'] . ' ' . $data_query_xml['fields']{$data_query['sort_field']}['query_name'] . ' ' . $index['snmp_index'], $data_query_xml['script_path'], $host_id) . "', '1')";
					}
				}
			}

			break;
	}

	if (sizeof($recache_stack)) {
		poller_update_poller_reindex_from_buffer($host_id, $data_query_id, $recache_stack);
	}
}

function poller_update_poller_reindex_from_buffer($host_id, $data_query_id, &$recache_stack) {
	/* set all fields present value to 0, to mark the outliers when we are all done */
	db_execute_prepared('UPDATE poller_reindex 
		SET present = 0 
		WHERE host_id = ? 
		AND data_query_id = ?', 
		array($host_id, $data_query_id));

	/* setup the database call */
	$sql_prefix   = 'INSERT INTO poller_reindex (host_id, data_query_id, action, op, assert_value, arg1, present) VALUES';
	$sql_suffix   = ' ON DUPLICATE KEY UPDATE action=VALUES(action), op=VALUES(op), assert_value=VALUES(assert_value), present=VALUES(present)';

	/* use a reasonable insert buffer, the default is 1MByte */
	$max_packet   = 256000;

	/* setup somme defaults */
	$overhead     = strlen($sql_prefix) + strlen($sql_suffix);
	$buf_len      = 0;
	$buf_count    = 0;
	$buffer       = '';

	foreach($recache_stack AS $record) {
		if ($buf_count == 0) {
			$delim = ' ';
		} else {
			$delim = ', ';
		}

		$buffer .= $delim . $record;

		$buf_len += strlen($record);

		if (($overhead + $buf_len) > ($max_packet - 1024)) {
			db_execute($sql_prefix . $buffer . $sql_suffix);

			$buffer    = '';
			$buf_len   = 0;
			$buf_count = 0;
		} else {
			$buf_count++;
		}
	}

	if ($buf_count > 0) {
		db_execute($sql_prefix . $buffer . $sql_suffix);
	}

	/* remove stale records FROM the poller reindex */
	db_execute_prepared('DELETE FROM poller_reindex 
		WHERE host_id = ? 
		AND data_query_id = ? 
		AND present = 0', array($host_id, $data_query_id));
}

/* process_poller_output - grabs data from the 'poller_output' table and feeds the *completed*
     results to RRDTool for processing
  @arg $rrdtool_pipe - the array of pipes containing the file descriptor for rrdtool
  @arg $remainder - don't use LIMIT if TRUE */
function process_poller_output(&$rrdtool_pipe, $remainder = FALSE) {
	global $config, $debug;

	static $have_deleted_rows = true;
	static $rrd_field_names = array();

	include_once($config['library_path'] . '/rrd.php');

	/* let's count the number of rrd files we processed */
	$rrds_processed = 0;
	$max_rows = 40000;

	if ($remainder) {
		/* check if too many rows pending */
		$rows = db_fetch_cell('SELECT COUNT(*) FROM poller_output');
		if ($rows > $max_rows && $have_deleted_rows === true) {
			$limit = ' LIMIT ' . $max_rows;
		}else{
			$limit = '';
		}
	}else{
		$limit = 'LIMIT ' . $max_rows;
	}

	$have_deleted_rows = false;

	/* create/update the rrd files */
	$results = db_fetch_assoc("SELECT po.output, po.time,
		UNIX_TIMESTAMP(po.time) as unix_time, po.local_data_id, dl.data_template_id,
		pi.rrd_path, pi.rrd_name, pi.rrd_num
		FROM poller_output AS po
		INNER JOIN poller_item AS pi
		ON po.local_data_id=pi.local_data_id
		AND po.rrd_name=pi.rrd_name
		INNER JOIN data_local AS dl
		ON dl.id=po.local_data_id
		ORDER BY po.local_data_id
		$limit");

	if (!sizeof($rrd_field_names)) {
		$rrd_field_names = array_rekey(db_fetch_assoc_prepared('SELECT ' . SQL_NO_CACHE . '
			CONCAT(dtr.data_template_id, "_", dif.data_name) AS keyname, GROUP_CONCAT(dtr.data_source_name) AS data_source_name 
			FROM data_template_rrd AS dtr
			INNER JOIN data_input_fields AS dif
			ON dtr.data_input_field_id = dif.id
			WHERE dtr.local_data_id=0
			GROUP BY dtr.data_template_id, dif.data_name'), 'keyname', array('data_source_name'));
	}

	if (sizeof($results)) {
		/* create an array keyed off of each .rrd file */
		foreach ($results as $item) {
			/* trim the default characters, but add single and double quotes */
			$value     = $item['output'];
			$unix_time = $item['unix_time'];
			$rrd_path  = $item['rrd_path'];
			$rrd_name  = $item['rrd_name'];

			$rrd_update_array[$rrd_path]['local_data_id'] = $item['local_data_id'];

			/* single one value output */
			if ((is_numeric($value)) || ($value == 'U')) {
				$rrd_update_array[$rrd_path]['times'][$unix_time][$rrd_name] = $value;
			/* special case of one value output: hexadecimal to decimal conversion */
			}elseif (is_hexadecimal($value)) {
				/* attempt to accomodate 32bit and 64bit systems */
				$value = str_replace(' ', '', $value);
				if (strlen($value) <= 8 || ((2147483647+1) == intval(2147483647+1))) {
					$rrd_update_array[$rrd_path]['times'][$unix_time][$rrd_name] = hexdec($value);
				}elseif (function_exists('bcpow')) {
					$dec = 0;
					$vallen = strlen($value);
					for ($i = 1; $i <= $vallen; $i++) {
						$dec = bcadd($dec, bcmul(strval(hexdec($value[$i - 1])), bcpow('16', strval($vallen - $i))));
					}
					$rrd_update_array[$rrd_path]['times'][$unix_time][$rrd_name] = $dec;
				}else{
					$rrd_update_array[$rrd_path]['times'][$unix_time][$rrd_name] = 'U';
				}
			/* multiple value output */
			}elseif (strpos($value, ':') !== false) {
				$values = preg_split('/\s+/', $value);

				foreach($values as $value) {
					$matches = explode(':', $value);

					if (sizeof($matches) == 2) {
						$fields = array();

						if (isset($rrd_field_names[$item['data_template_id'] . '_' . $matches[0]])) {
							$field_map = $rrd_field_names[$item['data_template_id'] . '_' . $matches[0]]['data_source_name'];

							if (strpos($field_map, ',') !== false) {
								$fields = explode(',', $field_map);
							}else{
								$fields[] = $field_map;
							}

							foreach($fields as $field) {
								cacti_log("Parsed MULTI output field '" . $matches[0] . ':' . $matches[1] . "' [map " . $matches[0] . '->' . $field . ']' , true, 'POLLER', ($debug ? POLLER_VERBOSITY_NONE:POLLER_VERBOSITY_MEDIUM));
								$rrd_update_array[$rrd_path]['times'][$unix_time][$field] = $matches[1];
							}
						}
					}
				}
			}

			/* fallback values */
			if ((!isset($rrd_update_array[$rrd_path]['times'][$unix_time])) && ($rrd_name != '')) {
				$rrd_update_array[$rrd_path]['times'][$unix_time][$rrd_name] = 'U';
			}else if ((!isset($rrd_update_array[$rrd_path]['times'][$unix_time])) && ($rrd_name == '')) {
				unset($rrd_update_array[$rrd_path]);
			}
		}

		/* make sure each .rrd file has complete data */
		reset($results);
		$k = 0;
		$data_ids = array();
		foreach ($results as $item) {
			$unix_time = $item['unix_time'];
			$rrd_path  = $item['rrd_path'];
			$rrd_name  = $item['rrd_name'];

			if (isset($rrd_update_array[$rrd_path]['times'][$unix_time])) {
				if ($item['rrd_num'] <= sizeof($rrd_update_array[$rrd_path]['times'][$unix_time])) {
					$data_ids[] = $item['local_data_id'];
					$k++;
					if ($k % 10000 == 0) {
						db_execute('DELETE FROM poller_output WHERE local_data_id IN (' . implode(',', $data_ids) . ')');
						$have_deleted_rows = true;
						$data_ids = array();
						$k = 0;
					}
				}else{
					unset($rrd_update_array[$rrd_path]['times'][$unix_time]);
				}
			}
		}

		if ($k > 0) {
			db_execute('DELETE FROM poller_output WHERE local_data_id IN (' . implode(',', $data_ids) . ')');
			$have_deleted_rows = true;
		}

		/* process dsstats information */
		dsstats_poller_output($rrd_update_array);

		api_plugin_hook_function('poller_output', $rrd_update_array);

		if (boost_poller_on_demand($results)) {
			$rrds_processed = rrdtool_function_update($rrd_update_array, $rrdtool_pipe);
		}

		$results = NULL;
		$rrd_update_array = NULL;

		/* to much records in poller_output, process in chunks */
		if ($remainder && strlen($limit)) {
			$rrds_processed += process_poller_output($rrdtool_pipe, $remainder);
		}
	}

	return $rrds_processed;
}

/** update_resource_cache - place the cacti website in the poller_resource_cache 
 * 
 *  for remote pollers to consume
 * @param int $poller_id    - The id of the poller.  0 is the main system
 * @return null             - No data is returned
 */
function update_resource_cache($poller_id = 0) {
	global $config;

	if ($config['cacti_server_os'] == 'win32') return;

	$mpath = $config['base_path'];
	$spath = $config['scripts_path'];
	$rpath = $config['resource_path'];

	$excluded_extensions = array('tar', 'gz', 'zip', 'tgz', 'ttf', 'z');

	$paths = array(
		'base'     => array('recursive' => false, 'path' => $mpath),
		'scripts'  => array('recursive' => true,  'path' => $spath),
		'resource' => array('recursive' => true,  'path' => $rpath),
		'plugins'  => array('recursive' => true,  'path' => $mpath . '/plugins'),
		'lib'      => array('recursive' => true,  'path' => $mpath . '/lib'),
		'include'  => array('recursive' => true,  'path' => $mpath . '/include'),
		'formats'  => array('recursive' => true,  'path' => $mpath . '/formats'),
		'locales'  => array('recursive' => true,  'path' => $mpath . '/locales'),
		'mibs'     => array('recursive' => true,  'path' => $mpath . '/mibs'),
		'cli'      => array('recursive' => true,  'path' => $mpath . '/cli')
	);

	if ($poller_id == 0) {
		foreach($paths as $type => $path) {
			if (is_readable($path['path'])) {
				$pathinfo = pathinfo($path['path']);
				if (isset($pathinfo['extension'])) {
					$extension = strtolower($pathinfo['extension']);
				}else{
					$extension = '';
				}

				/* exclude spurious extensions */
				$exclude = false;
				if (array_search($extension, $excluded_extensions, true) !== false) {
					$exclude = true;
				}

				if (!$exclude) {
					cache_in_path($path['path'], $type, $path['recursive']);
				}
			}else{
				cacti_log("ERROR: Unable to read the " . $type . " path '" . $path['path'] . "'", false, 'POLLER');
			}
		}
	}else{
		foreach($paths as $type => $path) {
			if (is_writable($config['scripts_path'])) {
				resource_cache_out($type, $path);
			}else{
				cacti_log("FATAL: Unable to write to the " . $type . " path '" . $path['path'] . "'", false, 'POLLER');
			}
		}
	}
}

/** cache_in_path - check to see if the directory in question has changed.  
 *  If so, send its data into the resource cache table 
 * 
 * @param string $path      - The path to look for changes
 * @param string $type      - The patch types being cached
 * @param bool   $recursive - Should the path be scanned recursively
 * @return null             - No data is returned
 */
function cache_in_path($path, $type, $recursive = true) {
	$settings_path = "md5dirsum_$type";

	$last_md5      = read_config_option($settings_path);
	$curr_md5      = md5sum_path($path, $recursive);

	if (empty($last_md5) || $last_md5 != $curr_md5) {
		cacti_log("NOTE: Detecting Resource Change.  Updating Resource Cache for '$path'", false, 'POLLER');
		update_db_from_path($path, $type, $recursive);
	}

	set_config_option($settings_path, $curr_md5);
}

/** update_db_from_path - store the actual file in the databases resource cache.
 *  Skip the include/config.php if it exists
 *
 * @param string $path      - The path to look for changes
 * @param string $type      - The patch types being cached
 * @param bool   $recursive - Should the path be scanned recursively
 * @return null             - No data is returned
 */
function update_db_from_path($path, $type, $recursive = true) {
	global $config;

	$pobject = dir($path);

	while(($entry = $pobject->read()) !== false) {
		if ($entry != '.' && $entry != '..') {
			if (is_dir($path . DIRECTORY_SEPARATOR . $entry)) {
				if ($recursive) {
					update_db_from_path($path . DIRECTORY_SEPARATOR . $entry, $type, $recursive);
				}
			}elseif (ltrim($path . DIRECTORY_SEPARATOR . $entry, DIRECTORY_SEPARATOR) != 'include' . DIRECTORY_SEPARATOR . 'config.php') {
				$save                  = array();
				$save['path']          = ltrim(trim(str_replace($config['base_path'], '', $path), '/ \\') . '/' . $entry, '/ \\');
				$save['id']            = db_fetch_cell_prepared('SELECT id FROM poller_resource_cache WHERE path = ?', array($save['path']));
				$save['resource_type'] = $type;
				$save['md5sum']        = md5_file($path . DIRECTORY_SEPARATOR . $entry);
				$save['update_time']   = date('Y-m-d H:i:s');
				$save['contents']      = base64_encode(file_get_contents($path . DIRECTORY_SEPARATOR . $entry));

				sql_save($save, 'poller_resource_cache');
			}
		}
	}

	$pobject->close();
}

/** resource_cache_out - push the cache from the cacti database to the 
 *  remote database.  Check PHP files for errors
 *
 * before placing them on the remote pollers file system.
 * @param string $type      - The path type being cached
 * @param string $path      - The path to store the contents
 * @return null             - No data is returned
 */
function resource_cache_out($type, $path) {
	global $config;

	$settings_path = "md5dirsum_$type";
	$php_path      = read_config_option('path_php');

	$last_md5      = read_config_option($settings_path);
	$curr_md5      = md5sum_path($path['path']);

	if (empty($last_md5) || $last_md5 != $curr_md5) {
		$entries = db_fetch_assoc('SELECT * FROM poller_resource_cache WHERE resource_type = ?', array($type));
		if (sizeof($entries)) {
			foreach($entries as $e) {
				$mypath = $path['path'] . DIRECTORY_SEPARATOR . $e['path'];

				if (file_exists($mypath)) {
					$md5sum = md5_file($mypath);
				}else{
					$md5sum = '';
				}

				if (is_dir(dirname($mypath))) {
					if ($md5sum != $e['md5sum']) {
						$info = pathinfo($mypath);
						$exit = -1;

						/* if the file type is PHP check syntax */
						if ($info['extension'] == 'php') {
							if ($config['cacti_server_os'] == 'win32') {
								$tmpfile = '%TEMP%' . DIRECTORY_SEPARATOR . 'cachecheck.php';
							}else{
								$tmpfile = '/tmp/cachecheck.php';
							}

							if (file_put_contents($tmpfile, base64_decode($e['contents'])) !== false) {
								$output = system($path_php . ' -l ' . $tmpfile, $exit);
								if ($exit == 0) {
									file_put_contents($mypath, base64_decode($e['contents']));
								}else{
									cacti_log("ERROR: PHP File '" . $mypath . "' from Cache has a Syntax error!", false, 'POLLER');
								}
							}else{
								cacti_log("ERROR: Unable to write file '" . $tmpfile . "' for PHP Syntax verification", false, 'POLLER');
							}
						}
					}
				}else{
					cacti_log("ERROR: Directory does not exist '" . dirname($mypath) . "'", false, 'POLLER');
				}
			}
		}
	}
}

/** md5sum_path - get a recursive md5sum on an entire directory.
 *
 * @param string $path      - The path to check for the md5sum
 * @param bool   $recursive - The path should be verified recursively
 * @return null             - No data is returned
 */
function md5sum_path($path, $recursive = true) {
    if (!is_dir($path)) {
        return false;
    }
    
    $filemd5s = array();
    $pobject = dir($path);

    while (($entry = $pobject->read()) !== false) {
		if ($entry == '.') {
			continue;
		}elseif ($entry == '..') {
			continue;
		}elseif ($entry == '') {
			continue;
		}elseif (strpos($entry, '.tgz') !== false) {
			continue;
		}elseif (strpos($entry, '.zip') !== false) {
			continue;
		}elseif (strpos($entry, '.tar') !== false) {
			continue;
		}elseif (strpos($entry, '.gz') !== false) {
			continue;
		}else{
             if (is_dir($path . DIRECTORY_SEPARATOR . $entry) && $recursive) {
                 $filemd5s[] = md5sum_path($path . DIRECTORY_SEPARATOR. $entry, $recursive);
             } else {
                 $filemd5s[] = md5_file($path . DIRECTORY_SEPARATOR . $entry);
             }
         }
    }

    $pobject->close();

    return md5(implode('', $filemd5s));
}
