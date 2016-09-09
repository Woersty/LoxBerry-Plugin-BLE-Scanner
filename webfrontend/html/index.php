<?php
// LoxBerry BLE Scanner Plugin 
// Christian Woerstenfeld - git@loxberry.woerstenfeld.de
// Version 1.7
// 09.09.2016 07:01:55

// Configuration parameters
$psubdir          =array_pop(array_filter(explode('/',pathinfo($_SERVER["SCRIPT_FILENAME"],PATHINFO_DIRNAME))));
$plugin_cfg_file 	="../../../../config/plugins/$psubdir/ble_scanner.cfg";
$general_cfg_file ="../../../../config/system/general.cfg";
$logfile 					="../../../../log/plugins/$psubdir/BLE-Scanner.log";
$showclouddns     ="/webfrontend/cgi/system/tools/showclouddns.pl";
$tag_prefix       ="BLE_";
$json_return      =array();
$tags_known       =array();
$error            =array();
$daemon_addr 			="127.0.0.1";
$daemon_port 			="12345";

// Enable logging
ini_set("error_log", $logfile);
ini_set("log_errors", 1);

// Defaults for inexistent variables
if (!isset($_REQUEST["mode"])) {$_REQUEST["mode"] = 'normal';}
if (!isset($_SERVER["HTTP_REFERER"])) {$_SERVER["HTTP_REFERER"] = 'direct';}

// Read log and exit
if ($_REQUEST["mode"] == "download_logfile")
{
	if (file_exists($logfile)) 
	{
		error_log( date('Y-m-d H:i:s ')."[LOG] Download logfile [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
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
		error_log( date('Y-m-d H:i:s ')."Error reading logfile! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
		die("Error reading logfile."); 
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

// Header output
header('Content-Type: application/json; charset=utf-8');

// Execute BLE-Scan
$client = stream_socket_client("tcp://$daemon_addr:$daemon_port", $errno, $errorMessage);
if ($client === false) 
{
	error_log( date('Y-m-d H:i:s ')."Error reading tags from Daemon at tcp://$daemon_addr:$daemon_port! Reason:".$errorMessage."\n", 3, $logfile);
	die(json_encode(array('error'=>"Error reading tags from Daemon tcp://$daemon_addr:$daemon_port",'result'=>"$errorMessage"))); 
}
else
{
	fwrite($client, "GET TAGS\n");
	$tags_scanned = json_decode (stream_get_contents($client),true);
	fclose($client);
}

// If result contain error, abort
if (isset($tags_scanned['error']))
{
	error_log( date('Y-m-d H:i:s ').$tags_scanned['error']." ".$tags_scanned['result'], 3, $logfile);
	die(json_encode($tags_scanned)); 
}

// Tag-MACs all uppercase and sort
$tags_scanned = array_map('strtoupper', array_unique($tags_scanned,SORT_STRING));

// Tag-MACs add prefix
$tags_scanned= array_map('convert_tag_format', $tags_scanned);
$tags_found=[];
$iterator=0;
foreach ($tags_scanned as $tags_scanned_line)
{
		$mac_rssi  = explode(";",$tags_scanned_line);
		$tags_found[$iterator]['mac']  = $mac_rssi[0];
		$tags_found[$iterator]['rssi'] = $mac_rssi[1];
		$iterator++;
}
############### Main ##############

if ($_REQUEST["mode"] == "scan")
{
	// Go through all online Tags
	while(list($tags_found_tag_key,$tags_found_tag_data) = each($tags_found))
  {
  	// If Tag is not already in tags_known-Array add it
  	if (!in_array_r($tags_found_tag_data['mac'],$tags_known))
  	{
  		// Add Tag
  		$current_tag                              	= count($tags_known);
	  	$tags_known["TAG".$current_tag]['id']     	= $tags_found_tag_data['mac'];
	  	$tags_known["TAG".$current_tag]['comment'] 	= '-';
	  	$tags_known["TAG".$current_tag]['found']   	= 1;
	  	$tags_known["TAG".$current_tag]['rssi']   	= $tags_found_tag_data['rssi'];
		}
	}
	// Return list of all online arrays 
	$json_return = $tags_known;
}
else
{
  // Read general config 
	$ms_cfg_array = parse_ini_file($general_cfg_file, TRUE);
	if (!$ms_cfg_array) 
	{
		error_log( date('Y-m-d H:i:s ')."Error reading general config! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
		die(json_encode(array('error'=>"Error reading general config!",'result'=>"Cannot open general.cfg config file for reading."))); 
	}

	// Read plugin config 
	$plugin_cfg_array = file($plugin_cfg_file);
	if (!$plugin_cfg_array) 
	{
		error_log( date('Y-m-d H:i:s ')."Error reading plugin config! [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
		die(json_encode(array('error'=>"Error reading plugin config!",'result'=>"Cannot open plugin config file for reading."))); 
	}
	// Parse plugin config
	foreach($plugin_cfg_array as $line) 
	{
		// Add the line to configured_tags-Array, if the value starts with "TAG" 
		if (substr($line,0,3) == "TAG")
		{
			$configured_tags[]=$line;
		}
	}
	
  // Go through all configured Tags
	if (isset($configured_tags))
	{
		while(list($configured_tags_tag_key,$configured_tags_tag_data) = each($configured_tags))
		  {
		  	// If found during prevoius scan, set found to 1, else to 0 
	  		$current_tag                              = count($tags_known);
				$tag_data_line                            = explode("=",$configured_tags_tag_data);
				$tag_data_array                           = explode(":",$tag_data_line[1]);
				if (isset($tag_data_array[0])) 							{ $tags_known["TAG$current_tag"]['id']      = trim($tag_data_array[0]); }
		  	if (isset($tag_data_array[1])) 							{ $tags_known["TAG$current_tag"]['use']     = trim($tag_data_array[1]); }
		  	if (isset($tag_data_array[2])) 							{ $tags_known["TAG$current_tag"]['ms_list'] = trim($tag_data_array[2]); }
		  	if (isset($tag_data_array[3])) 							{ $tags_known["TAG$current_tag"]['comment'] = trim($tag_data_array[3]); }
				if (isset($tag_data_array[0])) 							{ $tags_known["TAG$current_tag"]['found']    = intval(in_array($tag_data_array[0],$tags_found)); } else { $tags_known["TAG$current_tag"]['found'] = 0; }
				
				// If Tag is checked, process it
				if ($tags_known["TAG".$current_tag]['use'] == "on")
				{
					// Read Loxone Miniserver list into ms_array
					$ms_array = explode ('~',$tags_known["TAG".$current_tag]['ms_list']);
					
					// Go through all Miniservers for this Tags
					while(list($ms_key,$ms_data) = each($ms_array))
					{
						// Split Miniserver from use-value
						$current_ms = explode ('^',strtoupper($ms_data));
						// If use-value is "ON" process Tag
						if ( $current_ms[1] == 'ON' )
						{
							// Read config data for this current Miniserver
							foreach( $ms_cfg_array as $ms_id => $ms_cfg_data ) 
						  {
						  	// Check if Cloud or Local
						    if (!isset($ms_cfg_data["IPADDRESS"]) || $ms_cfg_data["IPADDRESS"] == "")
						    {
						    	$ms_cfg_data["IPADDRESS"]='';
						    }
						  	if (!isset($ms_cfg_data["CLOUDURL"]) || $ms_cfg_data["CLOUDURL"] == "")
						    {
						    	$ms_cfg_data["CLOUDURL"]='';
						    }
						    // If configured Miniserver is current Miniserver process it
						    if( strtoupper($ms_cfg_data["IPADDRESS"]) == $current_ms[0] || strtoupper($ms_cfg_data["CLOUDURL"]) == $current_ms[0]) 
						    {
									// Assign parameters to variables									
									if ( $ms_cfg_array[$ms_id]['USECLOUDDNS'] == 1 )
									{
										// Read current IP and Port when usin CloudDNS
										$LoxCloudURL      	=$ms_cfg_array[$ms_id]['CLOUDURL'];
										$clouddnsinfo_line 	=exec($ms_cfg_array['BASE']['INSTALLFOLDER']."$showclouddns ".$ms_cfg_data["CLOUDURL"]." 2>&1",$clouddnsinfo_data, $return_code);
										$clouddnsinfo_array =explode(":",$clouddnsinfo_data[0]);
										// Set Host & Port
										$LoxHost      			=$clouddnsinfo_array[0]; 
										$LoxPort      			=$clouddnsinfo_array[1];
									}
									else
									{
										// Set Host & Port
										$LoxHost      =$ms_cfg_array[$ms_id]['IPADDRESS'];
										$LoxPort      =$ms_cfg_array[$ms_id]['PORT'];
									}
									// Set User & Pass
									$LoxUser			=	$ms_cfg_array[$ms_id]['ADMIN'];
									$LoxPassword	= $ms_cfg_array[$ms_id]['PASS'];
									// If found during scan, set to 1, else to 0 in Virtual Input for Miniserver
									$LoxURL  = $LoxHost.':'.$LoxPort.'/dev/sps/io/'.$tags_known["TAG$current_tag"]['id'].'/'.$tags_known["TAG$current_tag"]['found'];
									$LoxLink = fopen('http://'.$LoxUser.':'.$LoxPassword.'@'.$LoxURL, "r");
									if (!$LoxLink) 
									{
										error_log( date('Y-m-d H:i:s ')."Can not sent Data to Miniserver! Unable to open http://xxx:xxx@".$LoxURL." [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
									}
									else
									{
										fclose($LoxLink);
									}
						      break;
						    }
						  }
						}
					}
				}
			}
		}
	$json_return = $tags_known;
}
if ($_REQUEST["mode"] == "scan")
{
	error_log( date('Y-m-d H:i:s ')."[OK] Scan: ".count($tags_known)." Tag(s) found [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
}
else
{
	error_log( date('Y-m-d H:i:s ')."[OK] Query: ".count($tags_known)." Tag(s) processed [".$_SERVER["HTTP_REFERER"]."]\n", 3, $logfile);
}
echo json_encode(array_values($json_return));
