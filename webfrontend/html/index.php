<?php
// LoxBerry BLE Scanner Plugin
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 0.30
// 29.11.2017 14:53:09

// Configuration parameters
$psubdir          =array_pop(array_filter(explode("/",pathinfo($_SERVER["SCRIPT_FILENAME"],PATHINFO_DIRNAME))));
$plugin_cfg_file  ="../../../../config/plugins/$psubdir/ble_scanner.cfg";
$general_cfg_file ="../../../../config/system/general.cfg";
$logfile          ="../../../../log/plugins/$psubdir/BLE-Scanner.log";
$showclouddns     ="/webfrontend/cgi/system/tools/showclouddns.pl";
$tag_prefix       ="BLE_";
$json_return      =array();
$tags_known       =array();
$error            =array();
$daemon_addr      ="127.0.0.1";
$daemon_port      ="12345";
$loxberry_id			="";

// Enable logging
$debug                =0;
ini_set("error_log",  $logfile);
ini_set("log_errors", 1);

// Defaults for inexistent variables
if (!isset($_REQUEST["mode"])) {$_REQUEST["mode"] = "normal";}
if (!isset($_SERVER["HTTP_REFERER"])) {$_SERVER["HTTP_REFERER"] = "direct";}

// Read log and exit
if ($_REQUEST["mode"] == "download_logfile")
{
  if (file_exists($logfile))
  {
    error_log( date("Y-m-d H:i:s ")."[PHP] Download logfile [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    header('Content-Description: File Transfer');
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="'.basename($logfile).'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($logfile));
    readfile($logfile);
  }
  else
  {
    error_log( date('Y-m-d H:i:s ')."[PHP] Error0001: Problem reading logfile! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    die("Error0001: Problem reading logfile.");
  }
  exit;
}
elseif ($_REQUEST["mode"] == "show_logfile")
{
  if (file_exists($logfile))
  {
    header('Content-Type: text/html');
    header('Content-Disposition: inline; filename="'.basename($logfile).'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    echo '<html><head><title>Logviewer</title><meta http-equiv="content-type" content="text/html; charset=utf-8"><link rel="shortcut icon" href="/system/images/icons/favicon.ico" /><link rel="icon" type="image/png" href="/system/images/favicon-32x32.png" sizes="32x32" /><link rel="icon" type="image/png" href="/system/images/favicon-16x16.png" sizes="16x16" /></head><body><div style="font-family:monospace;">';
    $trimmed = file("$logfile", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  	$loglines = count($trimmed);
  	echo "<script>function cancelTimeoutOnClick() { if (timerHandle) { clearTimeout(timerHandle); timerHandle = 0; } } \n   timerHandle = setTimeout(function(){location = ''}, 1000);</script><div style='cursor:hand;' onclick='cancelTimeoutOnClick();'><u>Stop refresh</u> -> showing last 60 lines every second...</div>";
  	$trimmed = array_slice($trimmed, -60);
  	foreach ($trimmed as $line_num => $line) 
    {
    	$line_num = $loglines - 59 + $line_num;
			if (stripos($line, "error") !== false) 
			{
    		$line = "<span style='background-color:#FFC0C0; color:#A00000;'>Line #<b>{$line_num}</b> : " . htmlspecialchars($line) . "</span><br>\n";
			}
			elseif (stripos($line, "[PHP]") !== false) 
			{
    		$line = "<span style='background-color:#FFFFC0; color:#0000A0;'>Line #<b>{$line_num}</b> : " . htmlspecialchars($line) . "</span><br>\n";
			}
			elseif (stripos($line, "[DAEMON]") !== false) 
			{
    		$line = "<span style='background-color:#C0FFFF; color:#00A0A0;'>Line #<b>{$line_num}</b> : " . htmlspecialchars($line) . "</span><br>\n";
			}
			elseif (stripos($line, "[Python]") !== false) 
			{
    		$line = "<span style='background-color:#C0FFC0; color:#00A000;'>Line #<b>{$line_num}</b> : " . htmlspecialchars($line) . "</span><br>\n";
			}
			else
			{
    		$line = "<span style='color:#c0c0c0;'>Line #<b>{$line_num}</b> : " . htmlspecialchars($line) . "</span><br>\n";
    	}
			echo $line;
		}
    echo "<a name='eop'></a><div style='cursor:hand;' onclick='cancelTimeoutOnClick();'><u>Stop refresh</u> -> showing last 60 lines every second...</div></div><script>window.scrollTo(0, document.body.scrollHeight); </script></body></html>";
  }
  else
  {
    error_log( date('Y-m-d H:i:s ')."[PHP] Error0001: Problem reading logfile! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    die("Error0001: Problem reading logfile.");
  }
  exit;
}

// Header output
header('Content-Type: application/json; charset=utf-8');

// Init Database
if ($_REQUEST["mode"] == "init_db")
{
  if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Request init_db received [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
  if ( strlen($_REQUEST["id"]) >= 1 )
  {
		$client = stream_socket_client("tcp://$daemon_addr:$daemon_port", $errno, $errorMessage);
		if ($client === false)
		{
		  error_log( date("Y-m-d H:i:s ")."[PHP] Error0013: Problem creating DB via Daemon ".$errorMessage."\n", 3, $logfile);
		  die(json_encode(array("title"=>"TXT_ERROR_NO_DAEMON","result"=>"$errorMessage")));
		}
		else
		{
      if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Sending CREATE_DB request to Daemon [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
		  fwrite($client, "CREATE_DB".escapeshellarg($_REQUEST["id"])."\n");
		  $creation_result = json_decode (stream_get_contents($client),true);
		  fclose($client);
		  die(json_encode($creation_result));
  	}
	}
  else
  {
    	error_log( date('Y-m-d H:i:s ')."[PHP] Error0010: Problem reading MySQL password! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
		  die(json_encode(array("title"=>"TXT_ERROR_READING_SQL_PW","result"=>htmlentities($_REQUEST["id"]))));
  }
  exit;
}

// Function for recursive Array search
function in_array_r($search_for, $in_what)
{
    foreach ($in_what as $value)
    {
        if ($value === $search_for || (is_array($value) && in_array_r($search_for, $value))) { return true; }
    }
    return false;
}
// Function to adapt MAC Address format
function convert_tag_format ($value)
{
  global $tag_prefix;
  return $tag_prefix.str_ireplace(":","_",trim($value));
}

// Execute BLE-Scan
if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Open connection to Daemon [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
$client = stream_socket_client("tcp://$daemon_addr:$daemon_port", $errno, $errorMessage);
stream_set_blocking ($client,false);
if ($client === false)
{
  error_log( date("Y-m-d H:i:s ")."[PHP] Error0002: reading tags from Daemon at tcp://$daemon_addr:$daemon_port! Reason:".$errorMessage."\n", 3, $logfile);
  die(json_encode(array("error"=>"Error0002: Problem reading tags from Daemon tcp://$daemon_addr:$daemon_port","result"=>"$errorMessage")));
}
else
{
  if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[PHP] Socket sending GET TAGS\n", 3, $logfile);
  fwrite($client, "GET TAGS\n");
  #sleep(4)
  $tags_scanned = "";
  $iterations = 0;
  while ( $tags_scanned == "" && $iterations < 3000 )
  {
  	$iterations++;
	  $tags_scanned = json_decode(stream_get_contents($client),true);
		usleep(10000); 
  }
  fclose($client);
  if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[PHP] Socket closed after $iterations iterations\n", 3, $logfile);
  if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[PHP] Have read from Daemon: ".implode(" ",$tags_scanned)."\n", 3, $logfile);
  if (!isset($tags_scanned) )
  {
    error_log( date("Y-m-d H:i:s ")."[PHP] Error0099: ".$tags_scanned["error"]." ".$tags_scanned["result"]."\n", 3, $logfile);
  	exit;
  }
  array_splice($tags_scanned, 0, 1);
}

// If result contain error, abort
if (isset($tags_scanned["error"]) )
{
  error_log( date("Y-m-d H:i:s ")."[PHP] Error0003: ".$tags_scanned["error"]." ".$tags_scanned["result"], 3, $logfile);
	die(json_encode($tags_scanned));
}
// Tag-MACs all uppercase and sort
if ( count($tags_scanned) > 0 )
{
  if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] OK: ".serialize($tags_scanned)."\n", 3, $logfile);
  $tags_scanned = array_map("strtoupper", array_unique($tags_scanned,SORT_STRING));
  // Tag-MACs add prefix
  $tags_scanned= array_map("convert_tag_format", $tags_scanned);
  $tags_found=[];
  $iterator=0;
  foreach ($tags_scanned as $tags_scanned_line)
  {
    $mac_rssi  = explode(";",$tags_scanned_line);
    $tags_found[$iterator]["mac"]  = $mac_rssi[0];
    if ( isset($mac_rssi[1]) )
    {
    $tags_found[$iterator]["rssi"] = $mac_rssi[1];
  	}
  	else
  	{
    $tags_found[$iterator]["rssi"] = -255;
  	}
    $iterator++;
  }
}
else
{
  if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Error0004: $tags_scanned\n", 3, $logfile);
  $tags_scanned = [];
  $tags_found=[];
}
############### Main ##############

if ( $debug == 1 ) error_log( date('Y-m-d H:i:s ')."[PHP] Entering main\n", 3, $logfile);

if ($_REQUEST["mode"] == "scan")
{
	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Mode: scan [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
  // Go through all online Tags
  while(list($tags_found_tag_key,$tags_found_tag_data) = each($tags_found))
  {
   	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Search for ".$tags_found_tag_data["mac"]." in known tags (scan)  [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    // If Tag is not already in tags_known-Array add it
    if (!in_array_r($tags_found_tag_data["mac"],$tags_known))
    {
      // Add Tag
      $current_tag                                = count($tags_known);
      $tags_known["TAG".$current_tag]["id"]       = $tags_found_tag_data["mac"];
      $tags_known["TAG".$current_tag]["comment"]  = "-";
      $tags_known["TAG".$current_tag]["found"]    = 1;
      $tags_known["TAG".$current_tag]["rssi"]     = $tags_found_tag_data["rssi"];
    	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Add ".$tags_known["TAG".$current_tag]["id"]." to known tags (scan)  [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    }
  }
  // Return list of all online arrays
  $json_return = $tags_known;
}
else
{
	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Mode: normal [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);

	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Reading general config [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
  // Read general config
  $ms_cfg_array = parse_ini_file($general_cfg_file, TRUE);
  if (!$ms_cfg_array)
  {
    error_log( date("Y-m-d H:i:s ")."[PHP] Error0002: Problem reading general config! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    die(json_encode(array("error"=>"Error reading general config!","result"=>"Cannot open general.cfg config file for reading.")));
  }

	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Reading plugin config [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
  // Read plugin config
  $plugin_cfg_array = file($plugin_cfg_file);
  if (!$plugin_cfg_array)
  {
    error_log( date("Y-m-d H:i:s ")."[PHP] Error0003: Problem reading plugin config! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
    die(json_encode(array("error"=>"Error reading plugin config!","result"=>"Cannot open plugin config file for reading.")));
  }

  // Parse plugin config
  foreach($plugin_cfg_array as $line)
  {
    // Add the line to configured_tags-Array, if the value starts with "TAG"
    if (substr($line,0,3) == "TAG")
    {
      $configured_tags[]=$line;
    }
		elseif (substr($line,0,11) == "LOXBERRY_ID")
		{
			$loxberry_id = substr($line,12);
		}
  }
  // No Line breaks
  $loxberry_id = preg_replace("/\r|\n/", "", $loxberry_id);
  // No Spaces allowed, replace by underscores
  $loxberry_id = preg_replace("/\s+/", "_", $loxberry_id);

	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Processing configured tags [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
  // Go through all configured Tags
  if (isset($configured_tags))
  {
    while(list($configured_tags_tag_key,$configured_tags_tag_data) = each($configured_tags))
      {
        // If found during prevoius scan, set found to 1, else to 0
        $current_tag                              = count($tags_known);
        $tag_data_line                            = explode("=",$configured_tags_tag_data);
        $tag_data_array                           = explode(":",$tag_data_line[1]);
        if (isset($tag_data_array[0]))              { $tags_known["TAG$current_tag"]["id"]      = trim($tag_data_array[0]); }
        if (isset($tag_data_array[1]))              { $tags_known["TAG$current_tag"]["use"]     = trim($tag_data_array[1]); }
        if (isset($tag_data_array[2]))              { $tags_known["TAG$current_tag"]["ms_list"] = trim($tag_data_array[2]); }
        if (isset($tag_data_array[3]))              { $tags_known["TAG$current_tag"]["comment"] = trim($tag_data_array[3]); }
        if (isset($tag_data_array[0]))              { $tags_known["TAG$current_tag"]["found"]    = intval(in_array_r($tag_data_array[0],$tags_found)); } else { $tags_known["TAG$current_tag"]["found"] = 0; }
				if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Processing tag ".$tags_known["TAG$current_tag"]["id"]." [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);

        // If Tag is checked, process it
        if ($tags_known["TAG".$current_tag]["use"] == "on")
        {
					if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Tag ".$tags_known["TAG$current_tag"]["id"]." is checked to use it - continue to process it [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
          // Read Loxone Miniserver list into ms_array
          $ms_array = explode ("~",$tags_known["TAG".$current_tag]["ms_list"]);

          // Go through all Miniservers for this Tags
          while(list($ms_key,$ms_data) = each($ms_array))
          {
            // Split Miniserver from use-value
            $current_ms = explode ("^",strtoupper($ms_data));
            // If use-value is "ON" process Tag
						if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Processing Tag ".$tags_known["TAG$current_tag"]["id"]." for MS #".$current_ms[0]." [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
            if ( $current_ms[1] == "ON" )
            {
								if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] MS #".$current_ms[0]." is checked to be used with ".$tags_known["TAG$current_tag"]["id"]." [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
                // Read config data for this current Miniserver
                // Check if Cloud or Local
                if ( $ms_cfg_array["MINISERVER".$current_ms[0]]["USECLOUDDNS"] == 1 )
                {
                  // Read current IP and Port when usin CloudDNS
                  $LoxCloudURL        =$ms_cfg_array["MINISERVER".$current_ms[0]]["CLOUDURL"];
                  $clouddnsinfo_line  =exec($ms_cfg_array["BASE"]["INSTALLFOLDER"]."$showclouddns ".$LoxCloudURL." 2>&1",$clouddnsinfo_data, $return_code);
                  $clouddnsinfo_array =explode(":",$clouddnsinfo_data[0]);
                  // Set Host & Port
                  $LoxHost            =$clouddnsinfo_array[0];
                  $LoxPort            =$clouddnsinfo_array[1];
                }
                else
                {
                  // Set Host & Port
                  $LoxHost      =$ms_cfg_array["MINISERVER".$current_ms[0]]["IPADDRESS"];
                  $LoxPort      =$ms_cfg_array["MINISERVER".$current_ms[0]]["PORT"];
                }
                // Set User & Pass
                $LoxUser      = $ms_cfg_array["MINISERVER".$current_ms[0]]["ADMIN"];
                $LoxPassword  = $ms_cfg_array["MINISERVER".$current_ms[0]]["PASS"];
                // If found during scan, set to 1, else to 0 in Virtual Input for Miniserver
                $LoxURL  = $LoxHost.":".$LoxPort."/dev/sps/io/".$loxberry_id.$tags_known["TAG$current_tag"]["id"]."/".$tags_known["TAG$current_tag"]["found"];
                $LoxLink = fopen("http://".$LoxUser.":".$LoxPassword."@".$LoxURL, "r");
                if (!$LoxLink)
                {
                  error_log( date("Y-m-d H:i:s ")."[PHP] Can not sent Data to Miniserver! Unable to open http://xxx:xxx@".$LoxURL." [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
                }
                else
                {
                	if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Send to Miniserver: $LoxURL\n", 3, $logfile);
                  fclose($LoxLink);
                }
                break;
            }
            else
            {
								if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] MS #".$current_ms[0]." is NOT checked to be used with ".$tags_known["TAG$current_tag"]["id"].". Ignoring it... [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
            }
          }
        }
        else
        {
					if ( $debug == 1 ) error_log( date("Y-m-d H:i:s ")."[PHP] Tag ".$tags_known["TAG$current_tag"]["id"]." is NOT checked to use it - ignoring it [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
        }
      }
    }
  $json_return = $tags_known;
}
if ($_REQUEST["mode"] == "scan")
{
  error_log( date("Y-m-d H:i:s ")."[PHP] Scan: ".count($tags_known)." Tag(s) found [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
}
else
{
  error_log( date("Y-m-d H:i:s ")."[PHP] Query: ".count($tags_known)." Tag(s) processed [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
}
echo json_encode(array_values($json_return));
