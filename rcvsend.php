#!/usr/bin/php
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
// <version>2.5</version>
// <email>vanesp@escurio.com</email>
// <date>2014-02-11</date>
// <summary>rcvsend receives text from a jeenode and stores records in a redis queue
//			text received over the serial line is transmitted to the jeenode</summary>

// version 2.1	-- PIR motion records are sent directly to the Redis channel

// version 2.2	-- a class added to daemonize this program

// version 2.3	-- extra exceptions caught on redis publishing

// version 2.4	-- stop filling the log with Redis exception messages, except when in debug mode

// version 2.5 -- use Redis lists to communicate with consume, drop MySQL

/**
 * System_Daemon Code
 *
 * If you run this code successfully, a daemon will be spawned
 * and stopped directly. You should find a log enty in
 * /var/log/simple.log
 *
 */

// Make it possible to test in source directory
// This is for PEAR developers only
ini_set('include_path', ini_get('include_path').':..');

// Include Class
error_reporting(E_ALL);
require_once "System/Daemon.php";

// No PEAR, run standalone
System_Daemon::setOption("usePEAR", false);

// Setup
$options = array(
	'appName' => 'rcvsend',
	'appDir' => dirname(__FILE__),
	'appDescription' => 'Receives data from Arduino and stores it in a redis queue',
	'authorName' => 'Peter van Es',
	'authorEmail' => 'vanesp@escurio.com',
	'sysMaxExecutionTime' => '0',
	'sysMaxInputTime' => '0',
	'sysMemoryLimit' => '1024M',
	'appRunAsGID' => 20,			// dialout group for /dev/ttyS1
	'appRunAsUID' => 1000,
);

System_Daemon::setOptions($options);
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon not yet started so this ".
	"will be written on-screen");

// Spawn Deamon!
System_Daemon::start();
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".
	System_Daemon::getOption("appName").
	"' spawned! This will be written to ".
	System_Daemon::getOption("logLocation"));


// get access credentials
include('access.php');

$device = "/dev/ttyS1";
$LEN = 128;						// records are max 128 bytes

date_default_timezone_set('Europe/Amsterdam');

// DEBUG
$debug = false;

// don't timeout!
set_time_limit(0);

// include Redis pub sub functionality
include('redis.php');

// set to the correct serial parameters
exec('stty -F '.$device.' 57600');

// open the serial port (later need to set settings)
$handle = fopen ($device, "r");
if($handle === FALSE) {
	$message = date('Y-m-d H:i') . " rcvsend.php: Failed to open device " .$device. "\n";
	System_Daemon::notice($message);
	// No point in continuing
	System_Daemon::stop();
	exit(1);
}

// Open the redis database
$i = 0;
sleep(30);	// wait a minute... for the database to be up and running
if (!$redis->isConnected()) {
	try {
		$redis->connect();
		$pubredis = true;
	}
	catch (Exception $e) {
		$pubredis = false;
		$message = date('Y-m-d H:i') . " Cannot connect to Redis for publishing " . $e->getMessage() . "\n";
		System_Daemon::notice($message);
		// No point in continuing
		System_Daemon::stop();
		exit(1);
	}
}

if ($debug) System_Daemon::info("rcvsend.php: Ready to receive...\n");
while (($buf = fgets($handle, $LEN)) !== false) {
	if (strlen($buf) > 1) {
		// process another line
		if ($debug) System_Daemon::info($buf);
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
			if ($pir) {
				if ($debug) System_Daemon::info("rcvsend.php: Publishing motion event room:".$roomid."\n");

				// update Redis using socketstream message
				$msg = new PubMessage;
				$msg->setParams('Motion', $roomid, '',1);
				try {
					$redis->publish('ss:event', json_encode($msg));
				}
				catch (Exception $e) {
					$message = date('Y-m-d H:i') . " rcvsend.php: Cannot publish to Redis " . $e->getMessage() . "\n";
					if ($debug) System_Daemon::notice($message);
				}
			}
		}

		// and insert into the database buffer, at the end...
		$value['buf'] = $buf;
		$value['tstamp'] = time();
		try {
			$redis->lpush ('queue',json_encode($value));
		}
		catch (Exception $e) {
			$message = date('Y-m-d H:i') . " rcvsend.php: Cannot lpush to Redis queue" . $e->getMessage() . "\n";
			if ($debug) System_Daemon::notice($message);
		}
	}
} // while

if (!feof($handle)) {
	$message = date('Y-m-d H:i') . " rcvsend.php: fgets failed\n";
	System_Daemon::notice($message);
}

fclose($handle);

System_Daemon::stop();

?>
