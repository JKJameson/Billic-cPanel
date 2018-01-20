<?php
class cPanel {
	public $settings = array(
		'orderform_vars' => array(
			'domain',
			'disk',
			'bandwidth',
			'parked_domains',
			'addon_domains',
			'subdomains',
			'email_accounts',
			'ftp_accounts',
			'databases',
			'mailing_lists',
			'has_shell',
			'has_dedicated_ip',
			'is_reseller',
			'reseller_accounts',
			'reseller_acl'
		) ,
		'description' => 'Create cPanel and WHM accounts.',
	);
	function generate_service_username($array) {
		$username = '';
		$firstname = strtolower($array['user']['firstname']);
		$lastname = strtolower($array['user']['lastname']);
		$allowedchars = 'a b c d e f g h i j k l m n o p q r s t u v w x y z';
		$allowedchars = explode(' ', $allowedchars);
		$part = '';
		for ($i = 0;$i < strlen($firstname);$i++) {
			if (in_array($firstname[$i], $allowedchars)) {
				$part.= $firstname[$i];
				break;
			}
		}
		$username.= $part[0];
		$part = '';
		for ($i = 0;$i < strlen($lastname);$i++) {
			if (in_array($lastname[$i], $allowedchars)) {
				$part.= $lastname[$i];
				break;
			}
		}
		$username.= $part[0];
		$username.= $array['service']['id'];
		return $username;
	}
	function units2MB($units) {
		$units = explode(' ', $units);
		switch ($units[1]) {
			case 'MB':
				return floor($units[0]);
			break;
			case 'GB':
				return floor($units[0] * 1024);
			break;
			case 'TB':
				return floor($units[0] * 1024 * 1024);
			break;
			case 'PB':
				return floor($units[0] * 1024 * 1024 * 1024);
			break;
		}
	}
	function user_cp($array) {
		global $billic, $db;
		$service = $array['service'];
		if (!empty($_GET['Ajax'])) {
			$billic->disable_content();
			if ($_GET['Ajax'] == 'nameservers') {
				$dataraw = $this->curl('json-api/dumpzone?api.version=1&domain=' . urlencode($service['domain']));
				$data = json_decode($dataraw, true);
				if (!is_array($data)) {
					err($dataraw);
				}
				if ($data['metadata']['result'] != 1) {
					err($this->cpanel_get_error($data));
				}
				$nameservers = array();
				$num = 1;
				foreach ($data['data']['zone'][0]['record'] as $record) {
					if ($record['type'] == 'NS' && $record['name'] == $service['domain'] . '.') {
						$nameservers['ns' . $num] = $record['nsdname'];
						$num++;
					}
				}
				die(json_encode($nameservers));
			} else if ($array['vars']['is_reseller'] == 'yes') { // WHM Account
				if ($_GET['Ajax'] == 'accounts') {
					// Get Account Limits
					$dataraw = $this->curl('json-api/acctcounts?api.version=1&user=' . urlencode($service['username']));
					$data = json_decode($dataraw, true);
					if (!is_array($data)) {
						err($dataraw);
					}
					if ($data['metadata']['result'] != 1) {
						err($this->cpanel_get_error($data));
					}
					$acctcounts = $data['data']['reseller'];
					$acct_limit = $acctcounts['limit'];
					$acct_used = ($acctcounts['active'] + $acctcounts['suspended']);
					if ($acct_limit == 0) {
						$percent = 100;
					} else {
						$percent = ceil((100 / $acct_limit) * $acct_used);
					}
					die(json_encode(array(
						'usageText' => $acct_used . ' of ' . $acct_limit,
						'percent' => $percent,
					)));
				}
				if ($_GET['Ajax'] == 'diskandbandwidth') {
					// Get Reseller Stats
					$dataraw = $this->curl('json-api/resellerstats?api.version=1&user=' . urlencode($service['username']));
					$data = json_decode($dataraw, true);
					if (!is_array($data)) {
						err($dataraw);
					}
					if ($data['metadata']['result'] != 1) {
						err($this->cpanel_get_error($data));
					}
					$summary = $data['data']['reseller'];
					$disk_overselling = false;
					$disk_limit = $summary['diskquota'];
					if ($summary['user'] == 'root' || $summary['diskoverselling'] == 0) {
						$disk_used = $summary['totaldiskalloc'];
					} else {
						$disk_used = $summary['diskused'];
						$disk_overselling = true;
					}
					if ($disk_limit == 'Unlimited') {
						$diskPercent = 0;
					} else if ($disk_limit == 0) {
						$diskPercent = 100;
					} else {
						$diskPercent = ceil((100 / $disk_limit) * $disk_used);
					}
					$bw_overselling = false;
					$bw_limit = $summary['bandwidthlimit'];
					if ($summary['user'] == 'root' || $summary['bwoverselling'] == 0) {
						$bw_used = $summary['totalbwalloc'];
					} else {
						$bw_used = $summary['totalbwused'];
						$bw_overselling = true;
					}
					if ($bw_limit == 'Unlimited') {
						$bwPercent = 0;
					} else if ($bw_limit == 0) {
						$bwPercent = 100;
					} else {
						$bwPercent = ceil((100 / $bw_limit) * $bw_used);
					}
					die(json_encode(array(
						'bwUsageText' => $bw_used . ' of ' . $bw_limit . ' MB',
						'bwPercent' => $bwPercent,
						'diskUsageText' => $disk_used . ' of ' . $disk_limit . ' MB',
						'diskPercent' => $diskPercent,
					)));
				}
			} else { // Normal cPanel Account
				/*				if ($_GET['Ajax']=='disk') {
					// Get Account Summary
					$dataraw = $this->curl('json-api/accountsummary?api.version=1&user='.urlencode($service['username']));
					$data = json_decode($dataraw, true);
					if (!is_array($data)) {
						err($dataraw);
					}
					if ($data['metadata']['result']!=1) {
						err($this->cpanel_get_error($data));
					}
					$summary = $data['data']['acct'][0];
					
					if ($summary['disklimit']=='unlimited') {
						$disk_limit = 'Unlimited';
					} else {
						preg_match('~([0-9]+)~', $summary['disklimit'], $disk_limit);
						$disk_limit = $disk_limit[0]; // MB
					}
					preg_match('~([0-9]+)~', $summary['diskused'], $disk_used);
					$disk_used = $disk_used[0]; // MB
					
					if ($disk_limit=='Unlimited') {
						$percent = 0;
					} else
					if ($disk_limit==0) {
						$percent = 100;	
					} else {
						$percent = ceil((100/$disk_limit)*$disk_used);
					}
					
					die(json_encode(array(
						'usageText' => $disk_used.' of '.$disk_limit.' MB',
						'percent' => $percent,
					)));
				}
				if ($_GET['Ajax']=='bandwidth') {
					// Get Bandwidth Usage
					$dataraw = $this->curl('json-api/showbw?api.version=1&searchtype=owner&search='.urlencode($service['username']));
					$data = json_decode($dataraw, true);
					if (!is_array($data)) {
						err($dataraw);
					}
					if ($data['metadata']['result']!=1) {
						err($this->cpanel_get_error($data));
					}
					$bw_used = 0;
					$bw_limit = 'Unlimited';
					foreach($data['data']['acct'] as $acct) {
						if ($acct['user']==$service['username']) {
							$bw_used = ceil($acct['totalbytes']/1024/1024); // MB
							if ($acct['limit']=='unlimited') {
								$bw_limit = 'Unlimited';
							} else {
								$bw_limit = ceil($acct['limit']/1024/1024); // MB
							}
						}
					}
					
					if ($bw_limit=='Unlimited') {
						$percent = 0;
					} else
					if ($bw_limit==0) {
						$percent = 100;	
					} else {
						$percent = ceil((100/$bw_limit)*$bw_used);
					}
					
					die(json_encode(array(
						'usageText' => $bw_used.' of '.$bw_limit.' MB',
						'percent' => $percent,
					)));
				}
				*/
				if ($_GET['Ajax'] == 'accountstats') {
					$dataraw = $this->curl('json-api/cpanel?cpanel_jsonapi_user=' . urlencode($service['username']) . '&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=StatsBar&cpanel_jsonapi_func=stat&display=ftpaccounts%7Cdiskusage%7Cbandwidthusage%7Cmailinglists%7Cdiskuage%7Csqldatabases%7Cparkeddomains%7Caddondomains%7Csubdomains%7Cemailaccounts&warnings=0&warninglevel=high&warnout=1&infinityimg=/home/example/infinity.png&infinitylang="infinity"&rowcounter=even');
					$dataraw = str_replace(chr(0xC2) . chr(0xA0) , ' ', $dataraw); // replace non-breaking spaces with normal spaces
					$data = json_decode($dataraw, true);
					if (!is_array($data)) {
						err($dataraw);
					}
					if (!is_array($data['cpanelresult']['data'])) {
						err($this->cpanel_get_error($data));
					}
					$array = array();
					foreach ($data['cpanelresult']['data'] as $k => $v) {
						$limit = strtolower($v['_max']);
						$used = $this->units2MB($v['count']);
						if ($used === null) {
							$used = 0;
						}
						if ($limit == 'unlimited') {
							$percent = 0;
						} else if ($limit == 0) {
							$percent = 100;
						} else {
							$percent = ceil((100 / $limit) * $used);
						}
						$array[$v['id']]['limit'] = $limit;
						$array[$v['id']]['used'] = $used;
						$array[$v['id']]['percent'] = $percent;
						$array[$v['id']]['usageText'] = $used . ' of ' . $limit;
						if ($v['id'] == 'diskusage' || $v['id'] == 'bandwidthusage') {
							$array[$v['id']]['usageText'].= ' MB';
						}
					}
					die(json_encode($array));
				}
			}
			exit;
		}
		$url = get_config('cpanel_url');
		$url = str_replace('2087', '2083', $url);
		$url = str_replace('2086', '2082', $url);
		echo '<table>';
		echo '<tr><td>Control Panel:</td><td><a href="' . $url . '" target="_blank">' . $url . '</a></td></tr>';
		echo '<tr><td>Username:</td><td>' . $service['username'] . '</td></tr>';
		echo '<tr><td>Password:</td><td>' . $billic->decrypt($service['password']) . '</td></tr>';
		echo '</table>';
		// START row
		echo '<div class="row">';
		// START left col
		echo '<div class="col-md-4">';
		// START Account Stats
		echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Account Stats</h3></div><div class="panel-body">';
		if ($array['vars']['is_reseller'] == 'yes') {
			echo 'cPanel Accounts <span class="label label-default pull-right" id="accountsUsageText">Loading...</span><div class="progress" id="accountsUsageBar" style="text-align:center"></div>';
		}
		echo 'Disk Usage <span class="label label-default pull-right" id="diskUsageText">Loading...</span><div class="progress" id="diskUsageBar" style="text-align:center"></div>';
		echo 'Bandwidth Usage <span class="label label-default pull-right" id="bandwidthUsageText">Loading...</span><div class="progress" id="bandwidthUsageBar" style="text-align:center"></div>';
		if ($array['vars']['is_reseller'] != 'yes') {
			echo 'Email Accounts <span class="label label-default pull-right" id="emailUsageText">Loading...</span><div class="progress" id="emailUsageBar" style="text-align:center"></div>';
			echo 'FTP Accounts <span class="label label-default pull-right" id="ftpUsageText">Loading...</span><div class="progress" id="ftpUsageBar" style="text-align:center"></div>';
			echo 'Databases <span class="label label-default pull-right" id="dbUsageText">Loading...</span><div class="progress" id="dbUsageBar" style="text-align:center"></div>';
			echo 'Parked Domains <span class="label label-default pull-right" id="parkedUsageText">Loading...</span><div class="progress" id="parkedUsageBar" style="text-align:center"></div>';
			echo 'Addon Domains <span class="label label-default pull-right" id="addonUsageText">Loading...</span><div class="progress" id="addonUsageBar" style="text-align:center"></div>';
			echo 'Subdomains <span class="label label-default pull-right" id="subUsageText">Loading...</span><div class="progress" id="subUsageBar" style="text-align:center"></div>';
			echo 'Mailing Lists <span class="label label-default pull-right" id="listsUsageText">Loading...</span><div class="progress" id="listsUsageBar" style="text-align:center"></div>';
		}
		// END Account Stats
		echo '</div></div>';
		// END left col
		echo '</div>';
		// START right col
		echo '<div class="col-md-8">';
		// START Server Information
		echo '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Server Information</h3></div><div class="panel-body">';
		$host = get_config('cpanel_url');
		echo 'Control Panel: <a href="' . $host . '">' . $host . '</a><br>';
		echo 'Name Servers: <b id="cPanelNameServers">' . $ns1 . '</b><br>';
		//echo 'Server Uptime:<br><b>9 Days, 22 hours</b>';
		// END Server Information
		echo '</div></div>';
		// END right col
		echo '</div>';
		// END row
		echo '</div>';
		echo '<script>
		cPanelStatProgress = 0;
		var cPanelStatNames = ["nameservers",';
		if ($array['vars']['is_reseller'] == 'yes') {
			echo '"accounts","diskandbandwidth"';
		} else {
			echo '"accountstats"';
		}
		echo '];
		addLoadEvent(function(){
			loadStat(cPanelStatNames[0]);
		});
		
		function correctPercentage(percent) {
			if (percent<0) {
				percent = 0;
			} else
			if (percent>100) {
				percent = 100;
			}
			return percent;
		}
		
		function pickBarColour(percent) {
			if (percent<50) {
				colour = "green";
			} else
			if (percent<75) {
				colour = "warning";
			} else {
				colour = "danger";
			}
			return colour;
		}
		
		function loadStat(name) {
			$.getJSON("Ajax/" + name, function( data ) {
				if (name == "nameservers") {
					$("#cPanelNameServers").text(data.ns1 + " and " + data.ns2);
				} else
				if (name == "accountstats") {
					var section = data.diskusage;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#diskUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#diskUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.bandwidthusage;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#bandwidthUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#bandwidthUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.ftpaccounts;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#ftpUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#ftpUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.mailinglists;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#listsUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#listsUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.sqldatabases;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#dbUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#dbUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.parkeddomains;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#parkedUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#parkedUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.addondomains;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#addonUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#addonUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.subdomains;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#subUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#subUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var section = data.emailaccounts;
					var percent = correctPercentage(section.percent);
					var colour = pickBarColour(percent);
					$("#emailUsageText").text(section.usageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#emailUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
				} else 
				if (name == "diskandbandwidth") {
					var percent = correctPercentage(data.diskPercent);
					var colour = pickBarColour(percent);
					$("#diskUsageText").text(data.diskUsageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#diskUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
					
					var percent = correctPercentage(data.bwPercent);
					var colour = pickBarColour(percent);
					$("#bandwidthUsageText").text(data.bwUsageText);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#bandwidthUsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
				} else {
					$("#"+name+"UsageText").text(data.usageText);
					var percent = correctPercentage(data.percent);
					var colour = pickBarColour(percent);
					if (percent<50) {
						leftPercent = "";
						rightPercent = percent+"%";
					} else {
						leftPercent = percent+"%";
						rightPercent = "";
					}
					$("#"+name+"UsageBar").html(\'<div class="progress-bar progress-bar-\'+colour+\'" role="progressbar" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100" style="width: \'+percent+\'%;text-align:center">\'+leftPercent+\'</div>\'+rightPercent);
				}
				cPanelStatProgress++;
				loadStat(cPanelStatNames[cPanelStatProgress]);
			});
		}
		</script>';
	}
	function suspend($array) {
		global $billic, $db;
		$service = $array['service'];
		$data = $this->curl('json-api/suspendacct?user=' . urlencode($service['username']));
		$data = json_decode($data, true);
		if (!is_array($data)) {
			return $data;
		}
		if (strpos($data['result'][0]['statusmsg'], 'suspendacct called for a user that does not exist') !== FALSE) {
			return true;
		}
		if ($data['result'][0]['status'] == 1) {
			return true;
		}
		return $this->cpanel_get_error($data);
	}
	function unsuspend($array) {
		global $billic, $db;
		$service = $array['service'];
		$data = $this->curl('json-api/unsuspendacct?user=' . urlencode($service['username']));
		$data = json_decode($data, true);
		if (!is_array($data)) {
			return $data;
		}
		if ($data['result'][0]['status'] == 1) {
			return true;
		}
		return $this->cpanel_get_error($data);
	}
	function terminate($array) {
		global $billic, $db;
		$service = $array['service'];
		// Terminate Reseller Accounts
		$dataraw = $this->curl('json-api/terminatereseller?api.version=1&user=' . urlencode($service['username']) . '&terminatereseller=0');
		$data = json_decode($dataraw, true);
		if (!is_array($data)) {
			return $dataraw;
		}
		if ($data['metadata']['result'] != 1 && strpos($data['metadata']['reason'], 'Not a reseller') === FALSE && strpos($data['metadata']['reason'], 'You do not have the required privileges to run') === FALSE) {
			return $this->cpanel_get_error($data);
		}
		// Terminate cPanel account
		$dataraw = $this->curl('json-api/removeacct?user=' . urlencode($service['username']));
		$data = json_decode($dataraw, true);
		if (!is_array($data)) {
			return $dataraw;
		}
		if ($data['metadata']['result'] != 1 && (strpos($data['result'][0]['statusmsg'], 'user ' . $service['username'] . ' does not exist') === FALSE && strpos($data['result'][0]['statusmsg'], 'account removed') === FALSE)) {
			return $this->cpanel_get_error($data);
		}
		return true;
	}
	function create($array) {
		global $billic, $db;
		$vars = $array['vars'];
		$service = $array['service'];
		$plan = $array['plan'];
		$user_row = $array['user'];
		if (empty($service['username'])) {
			$service['username'] = $this->generate_service_username($array);
			$db->q('UPDATE `services` SET `username` = ? WHERE `id` = ?', $service['username'], $service['id']);
		}
		if (empty($service['password'])) {
			$service['password'] = $billic->encrypt(strtolower($billic->rand_str(10)));
			$db->q('UPDATE `services` SET `password` = ? WHERE `id` = ?', $service['password'], $service['id']);
		}
		$disk = $this->units2MB($vars['disk']);
		$bandwidth = $this->units2MB($vars['bandwidth']);
		if ($vars['is_reseller'] == 'yes') {
			$disk = round($disk / 10);
			$bandwidth = round($bandwidth / 10);
		}
		$dataraw = $this->curl('json-api/createacct??api.version=1&user=' . urlencode($service['username']) . '&domain=' . urlencode($service['domain']) . '&quota=' . $disk . '&password=' . urlencode($billic->decrypt($service['password'])) . '&frontpage=no' . // Frontpage is obsolete.. why is it still in cPanel? :/
		'&ip=' . ($vars['has_dedicated_ip'] == 'yes' ? '1' : '0') . '&hasshell=' . ($vars['has_shell'] == 'yes' ? '1' : '0') . '&contactemail=' . urlencode($user_row['email']) . '&maxftp=' . urlencode($vars['ftp_accounts']) . '&maxsql=' . urlencode($vars['databases']) . '&maxpop=' . urlencode($vars['email_accounts']) . '&maxlst=' . urlencode($vars['mailing_lists']) . '&maxsub=' . urlencode($vars['subdomains']) . '&maxpark=' . urlencode($vars['parked_domains']) . '&maxaddon=' . urlencode($vars['addon_domains']) . '&bwlimit=' . $bandwidth . '&maxsub=' . urlencode($vars['subdomains']));
		$data = json_decode($dataraw, true);
		if (!is_array($data)) {
			return $dataraw;
		}
		if ($data['result'][0]['status'] != 1) {
			return $this->cpanel_get_error($data);
		}
		if ($vars['is_reseller'] == 'yes') {
			// Get IP
			$dataraw = $this->curl('json-api/accountsummary?api.version=1&user=' . urlencode($service['username']));
			$data = json_decode($dataraw, true);
			if (!is_array($data)) {
				return $dataraw;
			}
			if ($data['metadata']['result'] != 1) {
				return $this->cpanel_get_error($data);
			}
			$ip = $data['data']['acct'][0]['ip'];
			if (!filter_var($ip, FILTER_VALIDATE_IP)) {
				return 'Invalid IP from cPanel (' . $ip . ')';
			}
			// Setup Reseller
			$dataraw = $this->curl('json-api/setupreseller?api.version=1&user=' . urlencode($service['username']) . '&makeowner=1');
			$data = json_decode($dataraw, true);
			if (!is_array($data)) {
				return $dataraw;
			}
			if ($data['metadata']['result'] != 1) {
				return $this->cpanel_get_error($data);
			}
			// Set Account Limit
			$dataraw = $this->curl('json-api/setresellerlimits?api.version=1&user=' . urlencode($service['username']) . '&enable_account_limit=1&account_limit=' . urlencode($vars['reseller_accounts']) . '&enable_resource_limits=1&bandwidth_limit=' . urlencode($this->units2MB($vars['bandwidth'])) . '&diskspace_limit=' . urlencode($this->units2MB($vars['disk'])));
			$data = json_decode($dataraw, true);
			if (!is_array($data)) {
				return $dataraw;
			}
			if ($data['metadata']['result'] != 1) {
				return $this->cpanel_get_error($data);
			}
			// Set ACL
			$dataraw = $this->curl('json-api/setacls?api.version=1&reseller=' . urlencode($service['username']) . '&acllist=' . urlencode($vars['reseller_acl']));
			$data = json_decode($dataraw, true);
			if (!is_array($data)) {
				return $dataraw;
			}
			if ($data['metadata']['result'] != 1) {
				return $this->cpanel_get_error($data);
			}
			if ($vars['has_dedicated_ip'] == 'yes') {
				// Set main IP
				$dataraw = $this->curl('json-api/setresellermainip?api.version=1&user=' . urlencode($service['username']) . '&ip=' . urlencode($ip));
				$data = json_decode($dataraw, true);
				if (!is_array($data)) {
					return $dataraw;
				}
				if ($data['metadata']['result'] != 1) {
					return $this->cpanel_get_error($data);
				}
			}
			// Set IP Delegation
			$dataraw = $this->curl('json-api/setresellerips?api.version=1&user=' . urlencode($service['username']) . '&ips=' . urlencode($ip) . '&delegate=1');
			$data = json_decode($dataraw, true);
			if (!is_array($data)) {
				return $dataraw;
			}
			if ($data['metadata']['result'] != 1) {
				return $this->cpanel_get_error($data);
			}
		}
		return true;
	}
	function cpanel_get_error($data) {
		global $billic, $db;
		if (!empty($data['result'][0]['statusmsg'])) {
			return $data['result'][0]['statusmsg'];
		}
		if (!empty($data['cpanelresult']['error'])) {
			return $data['cpanelresult']['error'];
		}
		if (!empty($data['metadata']['reason'])) {
			return $data['metadata']['reason'];
		}
		return substr(serialize($data) , 0, 255);
	}
	function curl($action) {
		global $billic, $db;
		$options = array(
			CURLOPT_URL => get_config('cpanel_url') . '/' . $action,
			CURLOPT_HTTPHEADER => array(
				'Authorization: WHM ' . get_config('cpanel_user') . ':' . preg_replace("'(\r|\n)'", '', get_config('cpanel_pass'))
			) ,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT => "Curl",
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 300,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
		);
		$ch = curl_init();
		curl_setopt_array($ch, $options);
		$data = curl_exec($ch);
		if ($data === false) {
			return 'Curl error: ' . curl_error($ch);
		}
		$data = trim($data);
		return $data;
	}
	function ordercheck($array) {
		global $billic, $db;
		$vars = $array['vars'];
		if (!(preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $vars['domain']) // valid chars check
		 && preg_match("/^.{1,253}$/", $vars['domain']) // overall length check
		 && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $vars['domain']) // length of each label
		) || strpos($vars['domain'], '.') === FALSE) {
			$billic->error('Invalid Domain. It should be something like your-domain.com', 'domain');
		}
		if (substr($vars['domain'], 0, 4) == 'www.') {
			$vars['domain'] = substr($vars['domain'], 4);
		}
		return strtolower($vars['domain']); // return the domain for the service to be called
		
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="cPanel"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>cPanel URL</td><td><input type="text" class="form-control" name="cpanel_url" value="' . safe(get_config('cpanel_url')) . '"></td></tr>';
			echo '<tr><td>cPanel User</td><td><input type="text" class="form-control" name="cpanel_user" value="' . safe(get_config('cpanel_user')) . '"></td></tr>';
			echo '<tr><td>cPanel Key</td><td><textarea class="form-control" name="cpanel_pass" style="height: 250px">' . safe(get_config('cpanel_pass')) . '</textarea></td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('cpanel_url', $_POST['cpanel_url']);
				set_config('cpanel_user', $_POST['cpanel_user']);
				set_config('cpanel_pass', $_POST['cpanel_pass']);
				$billic->status = 'updated';
			}
		}
	}
}
