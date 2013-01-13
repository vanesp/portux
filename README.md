portux
======

Code on Portux platform to process Arduino messages

The code essentially consists of two processes:
- rcvsend which is started from inittab, opens the serial port and stores all received data in a local MySQL database
- consume which runs every minute, retrieves records from the local database, and interprets them, and stores them in
  the remote database on the server.
  Additionally it sends the values to Pachube for updating

