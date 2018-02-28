<?php
// LoxBerry BLE Scanner Plugin
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 2018.2.28
// 28.02.2018 23:04:41

// Error Reporting 
error_reporting(~E_ALL & ~E_STRICT);     // Alle Fehler reporten (Außer E_STRICT)
ini_set("display_errors", false);        // Fehler nicht direkt via PHP ausgeben
ini_set("log_errors", 1);
require_once "loxberry_system.php";
$L = LBSystem::readlanguage("language.ini");
ini_set("error_log", $lbplogdir."/BLE-Scanner.log"); 

// Configuration parameters
#$psubdir          =array_pop(array_filter(explode("/",pathinfo($_SERVER["SCRIPT_FILENAME"],PATHINFO_DIRNAME))));
$database		  ="/tmp/ble_scanner.dat";   
$plugin_cfg_file  =$lbpconfigdir."/ble_scanner.cfg";
$general_cfg_file ="../../../../config/system/general.cfg";
$tag_prefix       ="BLE_";
$json_return      =array();
$tags_known       =array();
$error            =array();
$daemon_addr      ="127.0.0.1";
$daemon_port      ="12345";
$loxberry_id			="";


$plugindata = LBSystem::plugindata();
$plugin_cfg["LOGLEVEL"] = $plugindata['PLUGINDB_LOGLEVEL'];
file_put_contents("/tmp/BLE-Scanner.loglevel", $plugin_cfg["LOGLEVEL"]);

$datetime    = new DateTime;
function debug($message = "", $loglevel = 7)
{
	global $plugin_cfg,$L;
	if ( intval($plugin_cfg["LOGLEVEL"]) >= intval($loglevel)  || $loglevel == 8 )
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
		  #if ( isset($message) && $message != "" ) notify ( LBPPLUGINDIR, $L['CC.MY_NAME'], $message);
		}
	}
	return;
}


// Defaults for inexistent variables
if (!isset($_REQUEST["mode"])) {$_REQUEST["mode"] = "normal";}
if (!isset($_SERVER["HTTP_REFERER"])) {$_SERVER["HTTP_REFERER"] = "direct";}

// Header output
header('Content-Type: application/json; charset=utf-8');

debug( "Reading Miniservers [".$_SERVER["HTTP_REFERER"]."]");
$ms = LBSystem::get_miniservers();
if (!is_array($ms)) 
{
	die(json_encode(array("error"=>"Error0010: No Miniservers configured.","result"=>"-")));
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
debug( "Open connection to Daemon [".$_SERVER["HTTP_REFERER"]."]", 5);

$client = stream_socket_client("tcp://$daemon_addr:$daemon_port", $errno, $errorMessage);
if ($client === false)
{
  debug( "Error0002: reading tags from Daemon at tcp://$daemon_addr:$daemon_port! Reason:".$errorMessage, 2);
  die(json_encode(array("error"=>"Error0002: Problem reading tags from Daemon tcp://$daemon_addr:$daemon_port","result"=>"$errorMessage")));
}
else
{
  stream_set_blocking ($client,false);
  debug( date('Y-m-d H:i:s ')."[PHP] Socket sending GET TAGS");
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
  debug( date('Y-m-d H:i:s ')."[PHP] Socket closed after $iterations iterations");
  debug( date('Y-m-d H:i:s ')."[PHP] Have read from Daemon: ".implode(" ",$tags_scanned), 6);
  if (!isset($tags_scanned) )
  {
    debug( "Error0099: ".$tags_scanned["error"]." ".$tags_scanned["result"], 4);
  	exit;
  }
  array_splice($tags_scanned, 0, 1);
}

// If result contain error, abort
if (isset($tags_scanned["error"]) )
{
  debug( "Error0003: ".$tags_scanned["error"]." ".$tags_scanned["result"], 3);
	die(json_encode($tags_scanned));
}
// Tag-MACs all uppercase and sort
if ( count($tags_scanned) > 0 )
{
  debug( "OK: ".serialize($tags_scanned), 5);
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
  debug( "Error0004: $tags_scanned", 3);
  $tags_scanned = [];
  $tags_found=[];
}


############### Main ##############

debug( date('Y-m-d H:i:s ')."[PHP] Entering main");

if ($_REQUEST["mode"] == "scan")
{
	debug( "Mode: scan [".$_SERVER["HTTP_REFERER"]."]");
  // Go through all online Tags
  while(list($tags_found_tag_key,$tags_found_tag_data) = each($tags_found))
  {
   	debug( "Search for ".$tags_found_tag_data["mac"]." in known tags (scan)  [".$_SERVER["HTTP_REFERER"]."]");
    // If Tag is not already in tags_known-Array add it
    if (!in_array_r($tags_found_tag_data["mac"],$tags_known))
    {
      // Add Tag
      $current_tag                                = count($tags_known);
      $tags_known["TAG".$current_tag]["id"]       = $tags_found_tag_data["mac"];
      $tags_known["TAG".$current_tag]["comment"]  = "-";
      $tags_known["TAG".$current_tag]["found"]    = 1;
      $tags_known["TAG".$current_tag]["rssi"]     = $tags_found_tag_data["rssi"];
    	debug( "Add ".$tags_known["TAG".$current_tag]["id"]." to known tags (scan)  [".$_SERVER["HTTP_REFERER"]."]");
    }
  }
  // Return list of all online arrays
  $json_return = $tags_known;
}
else
{
	debug( "Mode: normal [".$_SERVER["HTTP_REFERER"]."]");

  
	debug( "Reading plugin config [".$_SERVER["HTTP_REFERER"]."]");

  // Read plugin config
  $plugin_cfg_array = file($plugin_cfg_file);
  if (!$plugin_cfg_array)
  {
    debug( "Error0003: Problem reading plugin config! [".$_SERVER["HTTP_REFERER"]."]", 3);
    die(json_encode(array("error"=>"Error reading plugin config!","result"=>"Cannot open plugin config file for reading.")));
  }

  // Parse plugin config
  foreach($plugin_cfg_array as $line)
  {
    // Add the line to configured_tags-Array, if the value starts with "TAG"
    $line = str_replace('"', '', $line);
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

	debug( "Processing configured tags [".$_SERVER["HTTP_REFERER"]."]");
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
		debug( "Processing tag ".$tags_known["TAG$current_tag"]["id"]." [".$_SERVER["HTTP_REFERER"]."]");
        // If Tag is checked, process it
        if ($tags_known["TAG".$current_tag]["use"] == "on")
        {
		  debug( "Tag ".$tags_known["TAG$current_tag"]["id"]." is checked to use it - continue to process it [".$_SERVER["HTTP_REFERER"]."]");
          // Read Loxone Miniserver list into ms_array
          $ms_array = explode ("~",$tags_known["TAG".$current_tag]["ms_list"]);
	       // Go through all Miniservers for this Tags
          while(list($ms_key,$ms_data) = each($ms_array))
          {
            // Split Miniserver from use-value
            $current_ms = explode ("^",strtoupper($ms_data));
            // If use-value is "ON" process Tag
			debug( "Processing Tag ".$tags_known["TAG$current_tag"]["id"]." for MS ".$ms[$current_ms[0]]['Name']." [".$_SERVER["HTTP_REFERER"]."]",6);
            if ( $current_ms[1] == "ON" )
            {
				debug( "MS #".$current_ms[0]." (".$ms[$current_ms[0]]['Name'].") is checked to be used with ".$tags_known["TAG$current_tag"]["id"]." [".$_SERVER["HTTP_REFERER"]."]",6);
                // Read config data for this current Miniserver
                // Check if Cloud or Local

                  $LoxHost            =$ms[$current_ms[0]]['IPAddress'];
                  $LoxPort            =$ms[$current_ms[0]]['Port'];
	              $LoxCredentials     =$ms[$current_ms[0]]['Credentials'];

                // Set User & Pass
                // If found during scan, set to 1, else to 0 in Virtual Input for Miniserver
                $LoxURL  = $LoxHost.":".$LoxPort."/dev/sps/io/".$loxberry_id.$tags_known["TAG$current_tag"]["id"]."/".$tags_known["TAG$current_tag"]["found"];
                $LoxLink = fopen("http://".$LoxCredentials."@".$LoxURL, "r");
                if (!$LoxLink)
                {
                  debug( "Can not sent Data to Miniserver! Unable to open http://xxx:xxx@".$LoxURL." [".$_SERVER["HTTP_REFERER"]."]", 3);
                }
                else
                {
                	debug( "Send to Miniserver: $LoxURL", 6);
                  fclose($LoxLink);
                }
            }
            else
            {
								debug( "MS #".$current_ms[0]." is NOT checked to be used with ".$tags_known["TAG$current_tag"]["id"].". Ignoring it... [".$_SERVER["HTTP_REFERER"]."]");
            }
          }
        }
        else
        {
					debug( "Tag ".$tags_known["TAG$current_tag"]["id"]." is NOT checked to use it - ignoring it [".$_SERVER["HTTP_REFERER"]."]");
        }
      }
    }
  $json_return = $tags_known;
}


if ($_REQUEST["mode"] == "scan")
{
  debug( "Scan: ".count($tags_known)." Tag(s) found [".$_SERVER["HTTP_REFERER"]."]",6);
}
else
{
  debug( "Query: ".count($tags_known)." Tag(s) processed [".$_SERVER["HTTP_REFERER"]."]",6);
}
echo json_encode(array_values($json_return));

