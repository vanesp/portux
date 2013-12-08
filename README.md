# Portux

Code on Portux platform to process Arduino messages

The code essentially consists of two processes:
- rcvsend which is started from inittab, opens the serial port and
  stores all received data in a local MySQL database
- consume which runs every minute, retrieves records from the local
  database, and interprets them, and stores them in
  the remote database on the server.

  Additionally it sends the values to Pachube for updating

### Redis interface

A php Redis interface is added to be able to Publish data values.

The interface selected is Predis (https://github.com/nrk/predis) and is installed
using Composer (http://getcomposer.org).

A composer.json file needs to be created, and then the command

    composer install

needs to be run in the directory of the project. That leads to the rerquired packages
being installed in the subdirectory vendor/. They can then be included in the PHP project

### Redis usage

The Redis datastore resides on machine rpi1.local and is accessible via
the standard port.

The subscribe messages are of the form:

    {
        "t" : "all",
        "e" : "portux",
        "p" : [ 'type', 'location', 'quantity','value']
    }

Where RNR sensors are split into <sensortype> Temperature / Humidity / Light / Motion
and <value> needs no more calculation. The order is changed from the previous version
because this allows easier subscription to e.g. all motion events, or all temperature events.

Additionally two kinds of message are sent:

* Motion messages (type = Motion, location is 2 (Studeerkamer) or 3 (Woonkamer) by rcvsend)
* Switch messages (type = Switch, location is 1..4, value is true (On) or false (Off))



### Transforming to a Daemon

Using System_Daemon, a PHP class that allows developers to create their own daemon 
applications on Linux systems. The class is focussed entirely on creating & 
spawning standalone daemons

More info at:

- [Blog Article: Create daemons in PHP][1]

  [1]: http://kevin.vanzonneveld.net/techblog/article/create_daemons_in_php/
  
Note that a file called rcvsend needs to be copied to /etc/init.d to start it up automatically,
and it needs to be initialised by root as:

    update-rc.d rcvsend start 20 2 3 4 5 . stop 20 0 1 6 .

To remove, execute

    update-rc.d rcvsend remove
    
