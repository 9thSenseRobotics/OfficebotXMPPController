#!/usr/bin/php
<?php
 
 // create-daemon.php
 // created 2011-09-08 by alaina hardie, 9th sense robotics
 // mountain view, ca

require_once('officebot-include.php'); 

include 'XMPPHP/XMPP.php';

#Use XMPPHP_Log::LEVEL_VERBOSE to get more logging for error reports
#If this doesn't work, are you running 64-bit PHP with < 5.2.6?
$conn = new XMPPHP_XMPP('talk.google.com', 5222, 'spottersu', 'spotGSP11', 'xmpphp', 'gmail.com', $printlog=true, $loglevel=XMPPHP_Log::LEVEL_INFO);
$conn->autoSubscribe();

$vcard_request = array();

function create_update_status ($fh)
// get the Create's status (battery capacity, etc.)
{
	global $conn, $createStatusTable;
	
	$sensorArray = array();
	
	fwrite ($fh, "\x80"); 
	usleep (1000);
	fwrite ($fh, "\x82"); 
	usleep (1000);
	fwrite ($fh, "\x8e");
	usleep (1000);
	fwrite ($fh, "\x06");
	fwrite ($fh, 0);
	usleep (1000);
	$bytestr = fread ($fh, 52);
	//echo strlen($bytestr) . "\n";
	if (strlen($bytestr) != 52)
	{	
	$finalArray['create_status'] = 'unavailable';
		
		return;
	}
	
	$s = unpack ("C*", $bytestr);
	
	$finalArray['bumpsAndWheelDrops'] = $s[1];
	$finalArray['wall'] = $s[2];
	$finalArray['cliff_left'] = $s[3];
	$finalArray['cliff_front_left'] = $s[4];
	$finalArray['cliff_front_right'] = $s[5];
	$finalArray['cliff_right'] = $s[6];
	$finalArray['virtual_wall'] = $s[7];
	$finalArray['lsd_wheel'] = $s[8];
	$finalArray['unused'] = ($s[9] << 8) & $s[10];
	$finalArray['infrared'] = $s[11];
	$finalArray['buttons'] = $s[12];
	$finalArray['distance'] = ($s[13] << 8) & $s[14];
	$finalArray['angle'] = ($s[15] << 8) & $s[16];
	$finalArray['charging_state'] = $s[17];
	$finalArray['voltage'] = ($s[18] << 8) & $s[19];
	$finalArray['current'] = ($s[20] << 8) & $s[21];
	$finalArray['battery_temperature'] = $s[22];
	$finalArray['battery_charge'] = ($s[23] << 8) & $s[24];
	$finalArray['battery_capacity'] = ($s[25] << 8) & $s[26];
	$finalArray['wall_signal'] = ($s[27] << 8) & $s[28];
	$finalArray['cliff_left_signal'] = ($s[29] << 8) & $s[30];
	$finalArray['cliff_front_left'] = ($s[31] << 8) & $s[32];	
	$finalArray['cliff_front_right'] = ($s[33] << 8) & $s[34];
	$finalArray['cliff_right'] = ($s[35] << 8) & $s[36];
	$finalArray['cargo_bay_digital_inputs'] = $s[37];
	$finalArray['cargo_bay_analog_signal'] = ($s[38] << 8) & $s[39];
	$finalArray['charging_sources_available'] = $s[40];
	$finalArray['oi_mode'] = $s[41];
	$finalArray['song_number'] = $s[42];
	$finalArray['song_playing'] = $s[43];
	$finalArray['number_of_stream_packets'] = $s[44];
	$finalArray['requested_velocity'] = ($s[45] << 8) & $s[46];
	$finalArray['requested_radius'] = ($s[47] << 8) & $s[48];
	$finalArray['requested_right_velocity'] = ($s[49] << 8) & $s[50];
	$finalArray['requested_left_velocity'] = ($s[51] << 8) & $s[52];
	$finalArray['create_status'] = 'available';
	
	$json_string = base64_encode(json_encode ($finalArray));
	
	//echo "$json_string\n";
	
	mysql_query ("update $createStatusTable set json_string =  '$json_string';");
	
}

function create_drive($fh, $vel, $rad)
{
	fwrite ($fh, "\x80"); 
	usleep (1000);
	fwrite ($fh, "\x82"); 
	usleep (1000);

    $vh = ($vel>>8)&0xff;
    $vl = ($vel&0xff);
    $rh = ($rad>>8)&0xff;
    $rl = ($rad&0xff);
    $str = sprintf ("\x89%c%c%c%c", $vh, $vl, $rh, $rl);
    fwrite($fh, $str); 
}
function create_forward($roomba) 
{
	global $current_state;
	$current_state = 'f';
	
    create_drive($roomba, 0x01f4, 0x8000); # 0x01f4= 200 mm/s, 0x8000=straight
}
function create_backward($roomba) 
{
	global $current_state;
	$current_state = 'b';
	
    create_drive($roomba, 0xff38, 0x8000); # 0xff38=-200 mm/s, 0x8000=straight
}
function create_left($roomba) 
{
	global $current_state;
	$current_state = 'l';
	
    create_drive($roomba, 0x00c8, 0x0001); # 0x01f4= 200 mm/s, 0x0001=spinleft
}
function create_right($roomba) 
{
	global $current_state;
	$current_state = 'r';
	
    create_drive($roomba, 0x00C8, 0xffff); # 0x01f4= 200 mm/s, 0xffff=spinright
}
function create_stop($roomba) 
{
	global $current_state;
	$current_state = 's';
    create_drive($roomba, 0x0000, 0x0000); # 0x01f4= 200 mm/s, 0xffff=spinright
}


system ("stty -F $arduinoPort 9600 raw -parenb -parodd cs8 -hupcl -cstopb clocal");
$arduinoFp = fopen ($arduinoPort, 'w+');
if (!$arduinoFp)
{
	echo (json_encode(array('ret' => $arduinoPort . ' controller open failed' )));
	exit;
}

fwrite($arduinoFp, 'y');
sleep (1);
// system ("stty -F $createPort 57600 raw -parenb -parodd cs8 -hupcl -cstopb clocal");
// if (!$createFp = fopen ($createPort, 'w+'))
// {
// 	echo (json_encode(array('ret' => $createPort . ' base open failed')));
// 	exit;
// }

$current_state = 's';

// one infinite loop (ha ha, get it?)
$count = 0;
$stopped = 1;
try {
    $conn->connect();	
	while (!$conn->isDisconnected())
	{
    	$payloads = $conn->processUntil(array('message', 'presence', 'end_stream', 'session_start', 'vcard'));
    	foreach($payloads as $event) {
    		$pl = $event[1];
    		if ($event[0] == 'session_start')
    		{
				$conn->getRoster();
				$conn->presence($status="Cheese!");
    		}

			$cmd = explode(' ', $pl['body']);
			if($cmd[0] == 'quit') $conn->disconnect();
			if($cmd[0] == 'break') $conn->send("</end>");
    		switch($event[0]) {
    			case 'message': 
    				print "---------------------------------------------------------------------------------\n";
    				print "Message from: {$pl['from']}\n";
    				if($pl['subject']) print "Subject: {$pl['subject']}\n";
    				print $pl['body'] . "\n";
    				print "---------------------------------------------------------------------------------\n";
    				$conn->message($pl['from'], $body="Thanks for sending me \"{$pl['body']}\".", $type=$pl['type']);
					$cmd = explode(' ', $pl['body']);
					print "command is {$cmd[0]}\n";
// 					switch($cmd[0]) 
// 					{
// 						case 'cf': // move it forward
// 							print "move it forward\n";
// 							create_forward($createFp);
// 							if (is_numeric($cmd[1]))
// 							{
// 								sleep ($cmd[1]);
// 							} else {
// 								sleep (1);
// 							}
// 							create_stop();
// 							break;
// 						case 'cb': // back it on up
// 							create_backward($createFp);
// 							if (is_numeric($cmd[1]))
// 							{
// 								sleep ($cmd[1]);
// 							} else {
// 								sleep (1);
// 							}
// 							create_stop();
// 							break;
// 						case 'cl': // turn to the left
// 							create_left($createFp);
// 							if (is_numeric($cmd[1]))
// 							{
// 								sleep ($cmd[1]);
// 							} else {
// 								sleep (1);
// 							}
// 							create_stop();
// 							break;
// 						case 'cr': // turn to the right
// 							create_right($createFp);
// 							if (is_numeric($cmd[1]))
// 							{
// 								sleep ($cmd[1]);
// 							} else {
// 								sleep (1);
// 							}
// 							create_stop();
// 							break;
// 						case 'cs': // stop movement
// 							echo 'stop command sent: ' . microtime(true) . " $elapsed_time $timeout_time stopped $stopped\n";
// 							create_stop($createFp);
// 							$stopped = 1;
// 							break;
// 						case 'pl': // pan left
// 							$acmd = 'a';
// 							if (is_numeric($cmd[1]))
// 							{
// 								$mult = $cmd[1] * 3;
// 							} else {
// 								$mult = 3;
// 							}
// 							fwrite($arduinoFp, $acmd . $acmd . $acmd, $mult);
// 							break;
// 						case 'pr': // pan right
// 							$acmd = 'd';
// 							if (is_numeric($cmd[1]))
// 							{
// 								$mult = $cmd[1] * 3;
// 							} else {
// 								$mult = 3;
// 							}
// 							fwrite($arduinoFp, $acmd . $acmd . $acmd, $mult);
// 							break;
// 						case 'pu': // tilt up
// 							$acmd = 'w';
// 							if (is_numeric($cmd[1]))
// 							{
// 								$mult = $cmd[1] * 3;
// 							} else {
// 								$mult = 3;
// 							}
// 							fwrite($arduinoFp, $acmd . $acmd . $acmd, $mult);
// 							break;
// 						case 'pd': // tilt down
// 							$acmd = 's';
// 							if (is_numeric($cmd[1]))
// 							{
// 								$mult = $cmd[1] * 3;
// 							} else {
// 								$mult = 3;
// 							}
// 							fwrite($arduinoFp, $acmd . $acmd . $acmd, $mult);
// 							break;
// 						default:
// 							$acmd = NULL;
// 							break;
// 					} // endswitch
	   				if($cmd[0] == 'quit') $conn->disconnect();
    				if($cmd[0] == 'break') $conn->send("</end>");
    				if($cmd[0] == 'vcard') {
						if(!($cmd[1])) $cmd[1] = $conn->user . '@' . $conn->server;
						// take a note which user requested which vcard
						$vcard_request[$pl['from']] = $cmd[1];
						// request the vcard
						$conn->getVCard($cmd[1]);
					// else added ALH 2011-09-08	
					} else {
 						fwrite($arduinoFp, $cmd[0]);
					}
    			break;
    			case 'presence':
    				print "Presence: {$pl['from']} [{$pl['show']}] {$pl['status']}\n";
    			break;
    			case 'session_start':
    			    print "Session Start\n";
			    	$conn->getRoster();
    				$conn->presence($status="Cheese!");
    			break;
				case 'vcard':
					// check to see who requested this vcard
					$deliver = array_keys($vcard_request, $pl['from']);
					// work through the array to generate a message
					print_r($pl);
					$msg = '';
					foreach($pl as $key => $item) {
						$msg .= "$key: ";
						if(is_array($item)) {
							$msg .= "\n";
							foreach($item as $subkey => $subitem) {
								$msg .= "  $subkey: $subitem\n";
							}
						} else {
							$msg .= "$item\n";
						}
					}
					// deliver the vcard msg to everyone that requested that vcard
					foreach($deliver as $sendjid) {
						// remove the note on requests as we send out the message
						unset($vcard_request[$sendjid]);
    					$conn->message($sendjid, $msg, 'chat');
					}
				break;
			} // endswitch
		} // end foreach
	} // endwhile
} catch(XMPPHP_Exception $e) {
    die($e->getMessage());
}
?>
