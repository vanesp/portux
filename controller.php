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
// <version>1.0</version>
// <email>vanesp@escurio.com</email>
// <date>2013-12-11</date>
// <summary>controller received messages from pub/sub redis and acts upon them</summary>


// version 1.0

// This program needs to be run from cron as a Daemon
//
// Currently it receives all data as Pub/Sub messages from the Redis instance stored
// and run on the rpi1.local machine (see redis.php) and it uses the Redis instance
// to retrieve the current values of the lights etc.
//
// It uses the Predis/Async library for this (see https://github.com/nrk/predis-async)
// and this needs https://github.com/nrk/phpiredis
//
// It controls the lights by sending a Redis PUB message with the Switch command, which
// is currently executed on the rpi1.local machine by the server running under socketstream
// under node.js (so the rpi1 machine needs to be up and running)
//
// When the Nodo control board for the KAKU switches is linked to the Portux serial port
// directly, it will read messages from the serial port (non-blocking) and write commands
// to the serial port directly instead, simplifying this program.
//
// The control logic will however, remain intact.


// access information
include('access.php');
$LOGFILE = "controller.log";		// log history of actions
$LEN = 128;						// records are max 128 bytes

// include Redis pub sub functionality
// include('redis.php');

// Use the Predis\Async library

require 'vendor/autoload.php';

// prepend a base path if Predis is not present in the "include_path".
// require 'Predis/Autoloader.php';
// Open Redis, catch exceptions
// since the dns does not always work, fix the ip address for rpi1.local
try {
    $client = new Predis\Async\Client('tcp://127.0.0.1:6379');
}
catch (Exception $e) {
    $message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
    error_log($message, 3, $LOGFILE);
    // Just return to prevent the daemon from crashing
    // exit(1);
    return;
}

date_default_timezone_set('Europe/Amsterdam');

// DEBUG and other flags
$debug = true;

// don't timeout!
set_time_limit(0);

$client->connect(function ($client) {
    echo "Connected to Redis, now listening for incoming messages...\n";

    $client->pubsub('ss:event', function ($event, $pubsub) {
        $message = "Received message `%s` from channel `%s` [type: %s].\n";

        $feedback = sprintf($message,
            $event->payload,
            $event->channel,
            $event->kind
        );

        echo $feedback;

        if ($event->payload === 'quit') {
            $pubsub->quit();
        }
    });
});

$client->getEventLoop()->run();

$iter = 0;
while ($iter < 100) {
    echo "Iteration ", $iter;
    $iter++;
    sleep (10);
}

// Say goodbye :-)
$info = $client->info();
print_r("Goodbye from Redis v{$info['redis_version']}!\n");

?>
