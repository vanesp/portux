<?php
// Git version

// <copyright> Copyright (c) 2012 All Rights Reserved,
// Escurio
// http://www.escurio.com/
//
// THIS CODE AND INFORMATION ARE PROVIDED "AS IS" WITHOUT WARRANTY OF ANY 
// KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
// IMPLIED WARRANTIES OF MERCHANTABILITY AND/OR FITNESS FOR A
// PARTICULAR PURPOSE.
//
// </copyright>
// <author>Peter van Es</author>
// <version>2.1</version>
// <email>vanesp@escurio.com</email>
// <date>2013-01-16</date>
// <summary>rcvsend receives text from a jeenode and stores records in a local database
//          text received over the serial line is transmitted to the jeenode</summary>

// version 2.1	-- PIR motion records are sent directly to the Redis channel

// set some variables
$HOST = "127.0.0.1";
$DATABASE = "portuxdb";
$DBUSER = "pruser";
$DBPASS = "Wel12Lekker?";
$LOGFILE = "sensor.log";		// log history of actions

$device = "/dev/ttyS1";
$LEN = 128;						// records are max 128 bytes

date_default_timezone_set('Europe/Amsterdam');

// DEBUG
$debug = false;

// don't timeout!
set_time_limit(0);

require 'vendor/autoload.php';

// prepend a base path if Predis is not present in the "include_path".
// require 'Predis/Autoloader.php';
Predis\Autoloader::register();

$redis = new Predis\Client(array(
    'scheme' => 'tcp',
    'host'   => 'rpi1.local',
    'port'   => 6379,
    // no timeouts on socket
    'read_write_timeout' => 0,
));

// function to open Redis
function openredis() {
    global $redis;
    // modified to catch exceptions...
    try {
        $redis = new Predis\Client(array(
            'scheme' => 'tcp',
            'host'   => 'rpi1.local',
            'port'   => 6379,
            // no timeouts on socket
            'read_write_timeout' => 0,
        ));
        if ($debug) echo "Succesfully connected to Redis\n";
    }
    catch (Exception $e) {
        $message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
        error_log($message, 3, $LOGFILE);
        exit(1);
    }
}

// function to open the database
function opendb () {
    global $HOST, $DBUSER, $DBPASS, $DATABASE, $LOGFILE;
    $link = false;
    // Open the database
    // Open the database connection
    $link = mysql_connect($HOST, $DBUSER, $DBPASS);
    if (!$link) {
 	    $message = date('Y-m-d H:i') . " Database connection failed " . mysql_error($link) . "\n";
	    error_log($message, 3, $LOGFILE);
    }

    // See if we can open the database
    $db = mysql_select_db ($DATABASE, $link);
    if (!$db) {
    	$message = date('Y-m-d H:i') . " Failed to open $DATABASE " . mysql_error($link) . "\n";
    	error_log($message, 3, $LOGFILE);
    	$link = false;
    }
    return $link;
}

// set to the correct serial parameters
exec('stty -F '.$device.' 57600');

// open the serial port (later need to set settings)
$handle = fopen ($device, "r");
if($handle === FALSE) {
	$message = date('Y-m-d H:i') . " Failed to open device " .$device. "\n";
	error_log($message, 3, $LOGFILE);
}

// Open the database
$i = 0;
while (!($link = opendb()) && $i<10) {
	sleep(10);
	if ($debug) echo "Trying database attempt ".$i."\n";
	$i++;
}

if ($i>=10) {
	$message = date('Y-m-d H:i') . " Cannot open database " . mysql_error($link) . "\n";
	error_log($message, 3, $LOGFILE);
	exit(1);
}

if ($debug) echo "Ready to receive...\n";
while (($buf = fgets($handle, $LEN)) !== false) {
    if (strlen($buf) > 1) {
        // process another line
        if ($debug) echo $buf;
        if (strpos($buf, "[")) {
            // skip this line, it tells us the program version
            continue;
        };
        if (strpos($buf, " A")) {
            // skip this line, it indicates startup of communication
            continue;
        };

        // check if it is a motion event...
        if (strpos($buf, "GNR") === 0) {
            $field = explode(" ",$buf);
            if ($debug) print_r ($field);
                // field[0] = GNR
                // field[1] = room id
                $roomid = $field[1] & 0x1F;		// node from the header
                // Room node. PIR is on if ACK is set
                $pir = (($field[1] & 0x20) == 0x20);
                if ($debug) echo "Publishing motion event.\n"; 							
                $channel = 'portux.'.$roomid.'.Motion';
                if ($pir) {
                    try {
                        $redis->publish($channel, 1);
                    }
                    catch (Exception $e) {
                        $message = date('Y-m-d H:i') . " Cannot publish to Redis " . $e->getMessage() . "\n";
                        error_log($message, 3, $LOGFILE);
                        // reinitialise the connection
                        openredis();
                    }
                }
         }

        // and insert into the database buffer        	
        $insertq = "INSERT INTO rcvlog SET ts=NOW(), s='".$buf."', bP=0";
        if ($debug) echo $insertq, "\n";
        if (($res = mysql_query ($insertq, $link))===false) {
            $message = date('Y-m-d H:i') . " Could not insert rcvlog " . mysql_error($link) . "\n";
            error_log($message, 3, $LOGFILE);
        }
    }
} // while

mysql_free_result ($result);	// result
mysql_close ($link);

if (!feof($handle)) {
	$message = date('Y-m-d H:i') . " fgets failed\n";
	error_log($message, 3, $LOGFILE);
}

fclose($handle);
?>
