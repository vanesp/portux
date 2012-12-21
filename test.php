<?php
// little endian, so least significant byte first
// Git version

$lsb = 0;
$msb = 237;
$binarydata = pack ("C2", $msb, $lsb);
// echo $binarydata;
// $binarydata = "\x6F\xFF";
// should be -145
$out = unpack ("sshort/", $binarydata);
print_r ($out);
?>
