<?php
// LoxBerry BLE Scanner Plugin Daemon
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 0.30
// 29.11.2017 14:53:17

// Configuration
$ble_scan         = dirname(__FILE__)."/blescan.py";
$psubdir          = basename(dirname(dirname(__FILE__)));
$python           = "/usr/bin/python";
$logfile          = dirname(__FILE__)."/../../../../../log/plugins/$psubdir/BLE-Scanner.log";
$daemon_port      = 12345;
$hci_cfg          = "/bin/hciconfig";
$hci_dev          = "hci0";
$debug 						= 0;
$valid						= 30; // Maximum time in seconds to keep a previously found Tag online after signal loss
$max_wait_python  = 3;  // Maximum time in seconds to wait for first results after BLE scan start 

// Error Logging
ini_set("error_log", $logfile);
ini_set("log_errors", 1);

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
    error_log( date('Y-m-d H:i:s ')."[DAEMON] Could not bind to socket: ".socket_strerror(socket_last_error())."\n", 3, $logfile);
    echo "Server could not bind to socket. Will try again in 3s.\n ".socket_strerror(socket_last_error())."\n";
    sleep(3);
  }
  else
  {
    echo "Server listening on port $daemon_port\n";
    error_log( date('Y-m-d H:i:s ')."[DAEMON] Server listening on port $daemon_port\n", 3, $logfile);
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
        if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Socket opening\n", 3, $logfile);
	      socket_getpeername($client, $raddr, $rport);
	      $client_request     = socket_read($client, 1024, PHP_NORMAL_READ);
	      if ( $debug == 1 ) print "[DAEMON] Received Connection from $raddr:$rport with request $client_request \n";
	      // Read TAGS
	      if (substr($client_request,0,8) == "GET TAGS")
	      {
          if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] GET TAGS ok\n", 3, $logfile);
	        $last_line =  exec("$hci_cfg $hci_dev 2>&1 ",$hci_result, $return_code);


	        if ($return_code)
	        {
	          error_log( date('Y-m-d H:i:s ')."[DAEMON] Error starting bluetooth! ($hci_dev) Reason:".$last_line."\n", 3, $logfile);
	          socket_write($client,json_encode(array('dummy'=>0,'error'=>"Error starting bluetooth ($hci_dev)",'result'=>"$last_line")));
	        }
	        else
	        {
	          $search = "UP RUNNING";
	          if (!array_filter($hci_result, function($var) use ($search) { return preg_match("/\b$search\b/i", $var); }))
	          {
	            error_log( date('Y-m-d H:i:s ')."[DAEMON] Bluetooth Device '$hci_dev' seems not 'UP and RUNNING' but:".$hci_result[2]."\n", 3, $logfile);
	            socket_write($client,json_encode(array('dummy'=>0,'error'=>"Bluetooth Device '$hci_dev' seems not 'UP and RUNNING'",'result'=>"State: ".trim(preg_replace('/\t+/', '', $hci_result[2])))));
	          }
	          else
	          {
	            $tags_scanned='';
	            $unique_tags_scanned = array("Dummy");
	            $tosend='';
	            if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Start Python\n", 3, $logfile);
	            shell_exec("$python $ble_scan > /dev/null 2>/dev/null &");
	            if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Python called, waiting $max_wait_python second(s)...\n", 3, $logfile);
							sleep($max_wait_python);
	            if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Reading Tags from Database which were online in the last $valid seconds.\n", 3, $logfile);
							$db = mysqli_connect("localhost", "ble_scanner", "ble_scanner", "plugin_ble_scanner");
							if(!$db)
							{
							  socket_write($client,json_encode(array('dummy'=>0,'error'=>"DB connect error",'result'=>mysqli_connect_error())));
							}
							else
							{
								$ergebnis = mysqli_query($db, "SELECT MAC,rssi,Timestamp from `ble_scanner` where TIME_TO_SEC(TIMEDIFF(now(), Timestamp)) < $valid;");
	            	$tags_scanned =array();
	            	while($row = mysqli_fetch_object($ergebnis))
								{
									$tags_scanned[] = $row->MAC.";".$row->rssi;
	   	          	if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Record: ".$row->MAC.";".$row->rssi."\n", 3, $logfile);
								}
							  foreach ($tags_scanned as $tags_scanned_line)
	              {

	   	          if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Tag data: ".$tags_scanned_line."\n", 3, $logfile);


	                  $mac_rssi  = explode(";",$tags_scanned_line);
	                  if ( !in_array_r($mac_rssi[0],$unique_tags_scanned) )
	                  {
	                    $unique_tags_scanned[] = $tags_scanned_line;
	                    echo $tags_scanned_line."\n";
	                  }
	              }
	              $tosend= json_encode($unique_tags_scanned);
	              socket_write($client,$tosend);
	   	          if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Send: ".$tosend."\n", 3, $logfile);
	           	}
		        }
	        }
	      }
	      // Keepalive - just send date
	      else if (substr($client_request,0,9) == "KEEPALIVE")
	      {
	          error_log( date('Y-m-d H:i:s ')."[DAEMON] Keepalive ok\n", 3, $logfile);
	          socket_write($client,json_encode(array('dummy'=>0,'error'=>"Keepalive",'result'=>date('Y-m-d H:i:s '))));
	      }
	      // Init Database
	      else if (substr($client_request,0,9) == "CREATE_DB")
	      {
						$mysql_root_pw =  str_ireplace(array("'","/"), "", substr($client_request,10,-2));
						$command = 'echo \'DROP DATABASE IF EXISTS `plugin_ble_scanner`; CREATE DATABASE IF NOT EXISTS `plugin_ble_scanner`; DROP USER "ble_scanner"@"%"; CREATE USER "ble_scanner"@"%" IDENTIFIED BY "ble_scanner"; GRANT USAGE ON `plugin\_ble\_scanner`.* TO "ble_scanner"@"%"; GRANT SELECT, EXECUTE, SHOW VIEW, ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES, CREATE VIEW, DELETE, DROP, EVENT, INDEX, INSERT, REFERENCES, TRIGGER, UPDATE, LOCK TABLES  ON `plugin\_ble\_scanner`.* TO "ble_scanner"@"%" WITH GRANT OPTION; FLUSH PRIVILEGES; USE `plugin_ble_scanner`; CREATE TABLE IF NOT EXISTS `ble_scanner` ( `MAC` varchar(17) NOT NULL,  `Timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,  `rssi` tinyint(4) NOT NULL DEFAULT "-128", PRIMARY KEY (`MAC`), UNIQUE KEY `MAC` (`MAC`) ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT="BLE Token Database"; DELETE FROM `ble_scanner`;\' |mysql -u root -p'.$mysql_root_pw.' 2>&1';
	          if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Create DB request with $command \n", 3, $logfile);
            $last_line =  exec("$command 2>&1",$result,$return_code);
            if ($return_code)
            {
              error_log( date('Y-m-d H:i:s ')."[DAEMON] Error creating DB Reason:".$last_line."\n", 3, $logfile);
              $result = implode(" ",$result);
            	if ( $result == "" ) $result = "General command execution error.";
	            socket_write($client,json_encode(array('title'=>"TXT_DB_CREATE_RESULT_ERROR",'result'=>"$result")));
            
            }
            else
            {
            	if ( $last_line == "" ) $last_line = "OK";
	          	socket_write($client,json_encode(array('title'=>"TXT_DB_CREATE_RESULT",'result'=>"$last_line")));
	      		}
	      }
	      else
	      {
	          error_log( date('Y-m-d H:i:s ')."[DAEMON] Invalid Daemon request\n", 3, $logfile);
	          socket_write($client, json_encode(array('dummy'=>0,'error'=>"Invalid Daemon request",'result'=>"I expect 'GET TAGS' or 'KEEPALIVE' but not '$client_request'")));
	      }
	      socket_close($client);
        if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[DAEMON] Socket closed\n", 3, $logfile);
	    }
	  }
  }
}
