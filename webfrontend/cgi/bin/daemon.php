<?php
// LoxBerry BLE Scanner Plugin Daemon
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 1.1
// 09.09.2016 07:02:08

// Configuration
$ble_scan         = dirname(__FILE__)."/blescan.py";
$psubdir          = basename(dirname(dirname(__FILE__)));
$python           = "/usr/bin/python";
$logfile 					= dirname(__FILE__)."/../../../../../log/plugins/$psubdir/BLE-Scanner.log";
$daemon_addr 			= "127.0.0.1";
$daemon_port 			= "12345";
$hci_cfg          = "/bin/hciconfig";
$hci_dev          = "hci0"; 

// Error Logging
ini_set("error_log", $logfile);
ini_set("log_errors", 1);

// Creating listening socket
$server 					= stream_socket_server("tcp://$daemon_addr:$daemon_port", $errno, $errorMessage);
if ($server === false)
{
	error_log( date('Y-m-d H:i:s ')."Could not bind to socket: $errorMessage\n", 3, $logfile);
	die(json_encode(array('error'=>"Could not bind to socket",'result'=>"$errorMessage")));
}

// Main program - waiting for requests
for (;;)
{
		// Init variables
		$tags_scanned				= array();
		$tags_scanned_line	= '';
		$last_line 					= '';
    $hci_result         = '';
		// Assign client
		$client = stream_socket_accept($server);
		if ($client)
		{
				$client_request     = stream_get_line($client, 1024, "\n");
				// Read TAGS
				if ($client_request == "GET TAGS")
				{
					$last_line =  exec("$hci_cfg $hci_dev 2>&1",$hci_result, $return_code);
					if ($return_code)
					{
						error_log( date('Y-m-d H:i:s ')."Error starting bluetooth! ($hci_dev) Reason:".$last_line."\n", 3, $logfile);
						fwrite($client,json_encode(array('error'=>"Error starting bluetooth ($hci_dev)",'result'=>"$last_line")));
					}
					else
					{
						$search = "UP RUNNING";
						if (!array_filter($hci_result, function($var) use ($search) { return preg_match("/\b$search\b/i", $var); }))
				  	{
							error_log( date('Y-m-d H:i:s ')."Bluetooth Device '$hci_dev' seems not 'UP and RUNNING' but:".$hci_result[2]."\n", 3, $logfile);
							fwrite($client,json_encode(array('error'=>"Bluetooth Device '$hci_dev' seems not 'UP and RUNNING'",'result'=>"State: ".trim(preg_replace('/\t+/', '', $hci_result[2])))));
						}
						else
						{
							$last_line =  exec("$python $ble_scan 2>&1",$tags_scanned, $return_code);
							if ($return_code)
							{
								error_log( date('Y-m-d H:i:s ')."Error reading tags! Reason:".$last_line."\n", 3, $logfile);
								fwrite($client,json_encode(array('error'=>"Error reading tags",'result'=>"$last_line")));
							}
							else
							{						
								$tosend= json_encode($tags_scanned );
								fwrite($client,$tosend);
							}						
						}						
					}
				}
				// Keepalive - just send date
				else if ($client_request == "KEEPALIVE")
				{
						error_log( date('Y-m-d H:i:s ')."Keepalive ok\n", 3, $logfile);
						fwrite($client,json_encode(array('error'=>"Keepalive",'result'=>date('Y-m-d H:i:s '))));
				}
				else
				{
						error_log( date('Y-m-d H:i:s ')."Invalid Daemon request\n", 3, $logfile);
						fwrite($client, json_encode(array('error'=>"Invalid Daemon request",'result'=>"I expect 'GET TAGS' or 'KEEPALIVE'")));
				}
				fclose($client);
		}
}
