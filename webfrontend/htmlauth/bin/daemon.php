<?php
// LoxBerry BLE Scanner Plugin Daemon
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// 28.02.2018 23:01:26

// Configuration
ini_set("log_errors", 0);
ini_set("display_errors", 0);
ini_set("error_log", "REPLACELBPLOGDIR/BLE-Scanner.log"); 
$ble_scan         = dirname(__FILE__)."/blescan.py";
$psubdir          = basename(dirname(dirname(__FILE__)));
$basedir          = dirname(dirname(dirname(dirname(dirname(dirname(__FILE__))))));
$python           = "/usr/bin/python";

// Configuration parameters
$plugin_cfg_file  ="REPLACELBPCONFIGDIR/ble_scanner.cfg"; 
$database		  ="/tmp/ble_scanner.dat";   
$daemon_port      = 12345;
$hci_cfg          = "/bin/hciconfig";
$hci_dev          = "hci0";
$valid						= 30; // Maximum time in seconds to keep a previously found Tag online after signal loss
$max_wait_python  = 3;  // Maximum time in seconds to wait for first results after BLE scan start 
file_put_contents("/tmp/BLE-Scanner.loglevel", 3);
// Enable loglevel changes on the fly and in Python
chmod("/tmp/BLE-Scanner.loglevel", 666);
chown("/tmp/BLE-Scanner.loglevel", "loxberry");
chgrp("/tmp/BLE-Scanner.loglevel", "loxberry");

$datetime    = new DateTime;
function debug($message = "", $loglevel = 7)
{
	global $plugin_cfg,$L;
	if ( intval($plugin_cfg["LOGLEVEL"]) >= intval($loglevel) )
	{
		switch ($loglevel)
		{
		    case 0:
		        // OFF
		        break;
		    case 1:
		        error_log( strftime("%A") ." <ALERT> PHP: ".$message );
		        break;
		    case 2:
		        error_log( strftime("%A") ." <CRITICAL> PHP: ".$message );
		        break;
		    case 3:
		        error_log( strftime("%A") ." <ERROR> PHP: ".$message );
		        break;
		    case 4:
		        error_log( strftime("%A") ." <WARNING> PHP: ".$message );
		        break;
		    case 5:
		        error_log( strftime("%A") ." <OK> PHP: ".$message );
		        break;
		    case 6:
		        error_log( strftime("%A") ." <INFO> PHP: ".$message );
		        break;
		    case 7:
		    default:
		        error_log( strftime("%A") ." PHP: ".$message );
		        break;
		}
		if ( $loglevel < 4 ) 
		{
			### if ( isset($message) && $message != "" ) notify ( LBPPLUGINDIR, $L['GENERAL.MY_NAME'], $message); ###FIXME
		}
	}
	return;
}


function in_array_r($search_for, $in_what)
{
		foreach ($in_what as $value)
		{
				if ($value === $search_for || (is_array($value) && in_array_r($search_for, $value))) { return true; }
		}
		return false;
}

// Main program - waiting for requests
for (;;)
{
	// Creating listening socket
	$server           = socket_create_listen($daemon_port); // instead of stream_socket_server
	if ($server === false)
	{
		$plugin_cfg["LOGLEVEL"] = file_get_contents("/tmp/BLE-Scanner.loglevel");
		debug( "[DAEMON] Could not bind to socket: ".socket_strerror(socket_last_error()), 3);
		echo "Server could not bind to socket. Will try again in 3s.\n".socket_strerror(socket_last_error())."\n";
		sleep(3);
	}
	else
	{
		$plugin_cfg["LOGLEVEL"] = file_get_contents("/tmp/BLE-Scanner.loglevel");
		echo "Server listening on port $daemon_port\n";
		debug( "[DAEMON] Server listening on port $daemon_port");
		// Init variables
		$tags_scanned       = array();
		$tags_scanned_line  = '';
		$last_line          = '';
		$hci_result         = '';
		$write  = NULL;
		$except = NULL;
		$sock_array = array($server);
		if (1 === socket_select($sock_array,$write,$except,NULL))
		{
			while( $client = socket_accept($server))
			{
				$plugin_cfg["LOGLEVEL"] = file_get_contents("/tmp/BLE-Scanner.loglevel");
				debug( "[DAEMON] Socket opening", 5);
				socket_getpeername($client, $raddr, $rport);
				$client_request     = socket_read($client, 1024, PHP_NORMAL_READ);
				debug ("[DAEMON] Received Connection from $raddr:$rport with request $client_request ");
				// Read TAGS
				if (substr($client_request,0,8) == "GET TAGS")
				{
					debug( "[DAEMON] GET TAGS ok",5);
					$last_line =  exec("$hci_cfg $hci_dev 2>&1 ",$hci_result, $return_code);
					if ($return_code)
					{
						debug( "[DAEMON] Error starting bluetooth! ($hci_dev) Reason:".$last_line, 3);
						socket_write($client,json_encode(array('dummy'=>0,'error'=>"Error starting bluetooth ($hci_dev)",'result'=>"$last_line")));
					}
					else
					{
						$search = "UP RUNNING";
						if (!array_filter($hci_result, function($var) use ($search) { return preg_match("/\b$search\b/i", $var); }))
						{
							debug( "[DAEMON] Bluetooth Device '$hci_dev' seems not 'UP and RUNNING' but:".$hci_result[2], 3);
							socket_write($client,json_encode(array('dummy'=>0,'error'=>"Bluetooth Device '$hci_dev' seems not 'UP and RUNNING'",'result'=>"State: ".trim(preg_replace('/\t+/', '', $hci_result[2])))));
						}
						else
						{
							$tags_scanned='';
							$unique_tags_scanned = array("Dummy");
							$tosend='';
							debug( "[DAEMON] Start Python", 5);
							shell_exec("$python $ble_scan > /dev/null 2>/dev/null &");
							debug( "[DAEMON] Python called, waiting $max_wait_python second(s)...");
							sleep($max_wait_python);
							debug( "[DAEMON] Reading Tags from Database which were online in the last $valid seconds.");
							debug( "[DAEMON] Open DB $database ", 5);
							$db = new SQLite3($database);
							if(!$db)
							{
								socket_write($client,json_encode(array('dummy'=>0,'error'=>"DB connect error",'result'=>$db->lastErrorMsg())));
							}
							$db->exec("CREATE TABLE IF NOT EXISTS ble_scanner (   MAC TEXT PRIMARY KEY,    Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,   rssi TINYINT DEFAULT '-128')");
							debug("[DAEMON] Create table returns ".$db->lastErrorMsg());
							$ergebnis =  $db->query("SELECT `MAC`, `rssi`, `Timestamp` from ble_scanner where strftime('%s','now') - strftime('%s',`Timestamp`) < $valid ;");
							if($ergebnis==FALSE)
							{
								debug($db->lastErrorMsg(),3);
								socket_write($client,json_encode(array('dummy'=>0,'error'=>"Error reading from database",'result'=>$db->lastErrorMsg())));
							}
							else
							{
								
								$tags_scanned =array();
								while ($row = $ergebnis->fetchArray()) 
								{
									$tags_scanned[] = $row['MAC'].";".$row['rssi'];
									debug( "[DAEMON] SQLite Record: ".$row['MAC'].";".$row['rssi'].";".$row['Timestamp']);
								}
								foreach ($tags_scanned as $tags_scanned_line)
								{
									debug( "[DAEMON] Tag data: ".$tags_scanned_line);
									$mac_rssi  = explode(";",$tags_scanned_line);
									if ( !in_array_r($mac_rssi[0],$unique_tags_scanned) )
									{
										$unique_tags_scanned[] = $tags_scanned_line;
										 echo $tags_scanned_line."\n";
									}
								}
								$tosend= json_encode($unique_tags_scanned);
								socket_write($client,$tosend);
								debug( "[DAEMON] Send: ".$tosend, 5);
							}
							$db->close();
						}
					}
				}
				// Keepalive - just send date
				else if (substr($client_request,0,9) == "KEEPALIVE")
				{
						$plugin_cfg["LOGLEVEL"] = file_get_contents("/tmp/BLE-Scanner.loglevel");
						debug( "[DAEMON] Keepalive ok", 5);
						socket_write($client,json_encode(array('dummy'=>0,'error'=>"Keepalive",'result'=>date('Y-m-d H:i:s '))));
				}
				else
				{
						$plugin_cfg["LOGLEVEL"] = file_get_contents("/tmp/BLE-Scanner.loglevel");
						debug( "[DAEMON] Invalid Daemon request", 4);
						debug( "[DAEMON] Invalid request: $client_request ", 7);
						socket_write($client, json_encode(array('dummy'=>0,'error'=>"Invalid Daemon request",'result'=>"I expect 'GET TAGS' or 'KEEPALIVE' ")));
				}
			}
			socket_close($client);
			debug( "[DAEMON] Socket closed", 5);
		}
	}
}
