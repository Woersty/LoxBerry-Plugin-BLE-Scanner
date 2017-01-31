<?php
// LoxBerry BLE Scanner Plugin Daemon
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 0.16
// 31.01.2017 19:43:52

// Configuration
$ble_scan         = dirname(__FILE__)."/blescan.py";
$psubdir          = basename(dirname(dirname(__FILE__)));
$python           = "/usr/bin/python";
$logfile          = dirname(__FILE__)."/../../../../../log/plugins/$psubdir/BLE-Scanner.log";
$daemon_port      = 12345;
$hci_cfg          = "/bin/hciconfig";
$hci_dev          = "hci0";

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
    error_log( date('Y-m-d H:i:s ')."Could not bind to socket: ".socket_strerror(socket_last_error())."\n", 3, $logfile);
    echo "Server could not bind to socket. Will try again in 10s.\n ".socket_strerror(socket_last_error());
    sleep(10);
  }
  else
  {
    echo "Server listening on port $daemon_port\n ";
    error_log( date('Y-m-d H:i:s ')."Server listening on port $daemon_port\n", 3, $logfile);
  }
  // Init variables
  $tags_scanned       = array();
  $tags_scanned_line  = '';
  $last_line          = '';
  $hci_result         = '';
  $null               = NULL;
  $sock_array = array($server);
  if (1 === socket_select($sock_array,$null,$null,$null))
  {
    while( $client = socket_accept($server))
    {
      socket_getpeername($client, $raddr, $rport);
      $client_request     = socket_read($client, 1024, PHP_NORMAL_READ);
      print "Received Connection from $raddr:$rport with request $client_request \n";
      // Read TAGS
      if (substr($client_request,0,8) == "GET TAGS")
      {
        $last_line =  exec("$hci_cfg $hci_dev 2>&1",$hci_result, $return_code);
        if ($return_code)
        {
          error_log( date('Y-m-d H:i:s ')."Error starting bluetooth! ($hci_dev) Reason:".$last_line."\n", 3, $logfile);
          socket_write($client,json_encode(array('error'=>"Error starting bluetooth ($hci_dev)",'result'=>"$last_line")));
        }
        else
        {
          $search = "UP RUNNING";
          if (!array_filter($hci_result, function($var) use ($search) { return preg_match("/\b$search\b/i", $var); }))
          {
            error_log( date('Y-m-d H:i:s ')."Bluetooth Device '$hci_dev' seems not 'UP and RUNNING' but:".$hci_result[2]."\n", 3, $logfile);
            socket_write($client,json_encode(array('error'=>"Bluetooth Device '$hci_dev' seems not 'UP and RUNNING'",'result'=>"State: ".trim(preg_replace('/\t+/', '', $hci_result[2])))));
          }
          else
          {
            $tags_scanned='';
            $uniqe_tags_scanned = array();
            $tosend='';
            $last_line =  exec("$python $ble_scan 2>&1",$tags_scanned, $return_code);
            if ($return_code)
            {
              error_log( date('Y-m-d H:i:s ')."Error reading tags! Reason:".$last_line."\n", 3, $logfile);
              socket_write($client,json_encode(array('error'=>"Error reading tags",'result'=>"$last_line")));
            }
            else
            {
              foreach ($tags_scanned as $tags_scanned_line)
              {
                  $mac_rssi  = explode(";",$tags_scanned_line);
                  if ( !in_array_r($mac_rssi[0],$uniqe_tags_scanned) )
                  {
                    $uniqe_tags_scanned[] = $tags_scanned_line;
                    echo $tags_scanned_line."\n";
                  }
              }
              $tosend= json_encode($uniqe_tags_scanned);
              socket_write($client,$tosend);
            }
          }
        }
      }
      // Keepalive - just send date
      else if (substr($client_request,0,9) == "KEEPALIVE")
      {
          error_log( date('Y-m-d H:i:s ')."Keepalive ok\n", 3, $logfile);
          socket_write($client,json_encode(array('error'=>"Keepalive",'result'=>date('Y-m-d H:i:s '))));
      }
      else
      {
          error_log( date('Y-m-d H:i:s ')."Invalid Daemon request\n", 3, $logfile);
          socket_write($client, json_encode(array('error'=>"Invalid Daemon request",'result'=>"I expect 'GET TAGS' or 'KEEPALIVE' but not '$client_request'")));
      }
      socket_close($client);
    }
  }
}
