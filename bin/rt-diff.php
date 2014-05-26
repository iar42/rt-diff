<?php

#######################################################
# Opin Kerfi: rt-diff
# Version: 1.0
#
# Description:
#   PHP script run from cron or manually that collects
#   info on all objects in Racktables, compares it from
#   last runtime and sends diff report via email if
#   differences are found.
#
# Author: Ingimar Robertsson <ingimar@ok.is>
#
# Installation:
#  See README file
#
# Version History:
#   1.0 - Initial version
#######################################################

## Racktables wwwroot directory:
$rtwwwroot = "/var/www/html/racktables";

## rt-diff installation root:
$rtdiffroot = "/opt/rt-diff";

## Path to diff command
$diffbin = "/usr/bin/diff";

## Recipients of email report:
$recipients = array(
	"admin1@somecorp.tld",
	"admin2@somecorp.tld",
);

## Sender of email report:
$sender_email = 'rtadmin1@somecorp.tld';
$sender_name = 'Racktables Changelog';

## SMTP mail server:
$smtphost = 'mailhost.somecorp.tld';

## Work and rt-diff archive directory:
$archive = "$rtdiffroot/archive";

#######################################################
## Hopefully no need to make any changes below
#######################################################

$script_mode = TRUE;
chdir($rtwwwroot);

include ('inc/init.php');
include ("$rtdiffroot/lib/class.phpmailer.php");
include ("$rtdiffroot/lib/class.smtp.php");

date_default_timezone_set('GMT');
$ts = date("Ymd-His");
$newout  = $archive . "/allobj-new.txt";
$oldout  = $archive . "/allobj-old.txt";
$tsout   = $archive . "/allobj-$ts.txt";
$diffout = $archive . "/allobj-$ts.diff";

## Scan Racktables objects and write to textfile:
$fp = fopen("$newout" , "w");
$allobjects = scanRealmByText ('object');
foreach ( $allobjects as $object ) {
	fwrite( $fp , FetchTxtObject($object) );
}
fclose($fp);

## Do we have an old run to compare with:
if( file_exists( $oldout ) ) {
	exec("$diffbin -u $oldout $newout", $diffarray);
	$difflines = count($diffarray);
	if( $difflines > 0 ) {
		## Found differences
		copy($newout , $tsout);
		rename($newout , $oldout);
		$difftext = "";
		foreach($diffarray as $diffline) {
			## Loop through diff output and format in "human readable" format
			if( preg_match('/^\-\-\-\s.*\s(\d\d\d\d-\d\d-\d\d) (\d\d:\d\d:\d\d)\..*/', $diffline, $matches) ) {
				$timefrom = $matches[1] . " " . $matches[2];
				continue;
			}
			if( preg_match('/^\+\+\+\s.*\s(\d\d\d\d-\d\d-\d\d) (\d\d:\d\d:\d\d)\..*/', $diffline, $matches) ) {
				$timeto = $matches[1] . " " . $matches[2];
				$difftext .= "Changes between $timefrom and $timeto\n";
				$difftext .= "=====================================================================================\n";
				continue;
			}
			if( preg_match('/^\*\*\*\s\d+,\d+\s\*\*\*\*$/', $diffline) ) { continue; }
			if( preg_match('/^\-\-\-\s\d+,\d+\s\-\-\-\-$/', $diffline) ) { continue; }
			$diffline = preg_replace( '/^\@\@ .* \@\@$/' , '' , $diffline );
			$diffline = preg_replace( '/^\+/' , '[ADDED] ' , $diffline );
			$diffline = preg_replace( '/^\-/' , '[REMOVED] ' , $diffline );
			$diffline = preg_replace( '/^\!/' , '[CHANGED] ' , $diffline );
			$difftext .= $diffline . "\n";
		}

		## Store formatted diff output in archive folder:
		$fpdiff = fopen("$diffout" , "w");
		fwrite( $fpdiff , $difftext );
		fclose($fpdiff);

		## Send email report:
		$email = new PHPMailer;
		$email->isSMTP();
		$email->CharSet = "UTF-8";
		$email->Host = $smtphost;
		$email->From = $sender_email;
		$email->FromName = $sender_name;
		foreach( $recipients as $recipient )
			$email->addAddress( $recipient );
		$email->isHTML(TRUE);
		$email->Subject = 'rt-diff: Racktables Object Changes';
		$email->Body    = preg_replace('/\n/','<br>',$difftext);
		$email->AltBody    = $difftext;
		if(!$email->send()) {
			echo 'Message could not be sent.';
			echo 'Mailer Error: ' . $email->ErrorInfo;
		}
	} else {
		## No differences
		rename($newout , $oldout);
	}
} else {
	## First run
	print "First run\n";
	rename($newout , $oldout);
}


#######################################################
#### FUNCTIONS:

function FetchTxtObject($object) {
	amplifyCell($object);

	$output = "";

	$prefix = $object[id] . " " . $object['dname'] . ": ";

	# Standard Object Information
	$output .= $prefix . "ID: " . $object['id'] . "\n";
	$output .= $prefix . "Common Name: " . $object['name'] . "\n";
	$output .= $prefix . "Object Type: " . decodeObjectType($object['objtype_id']) . "\n";
	$output .= $prefix . "Visible Label: " . $object['label'] . "\n";
	$output .= $prefix . "Asset Tag: " . $object['asset_no'] . "\n";
	if( $object['container_name'] )
		$output .= $prefix . "Container: " . $object['container_name'] . "\n";
	if( $object['rack_id'] ) {
		$t_rackobj = spotEntity('rack' , $object['rack_id']);
		$location =  "Loc:" . $t_rackobj['location_name'] . " / ";
		$location .= "Row:" . $t_rackobj['row_name'] . " / ";
		$location .= "Rack:" . $t_rackobj['name'];
		$output .= $prefix . "Rack ID: " . $object['rack_id'] . " ($location)\n";
	}

	# All Attributes, don't print empty ones
	$t_allattribs = getAttrValues($object[id]);
	foreach( $t_allattribs as $attr ) {
		if( strlen($attr['value']) ) {
			# Attribute is not empty
			if( $attr['type'] == 'date' ) {
				$output .= $prefix . $attr['name'] . ": ";
				$output .= datetimestrFromTimestamp($attr['value']) . "\n";
			} else {
				$output .= $prefix . $attr['name'] . ": " . $attr['a_value'] . "\n";
			}
		}
	}

	# Explicit Tags
	$etagcount = count($object['etags']);
	if($etagcount)
		$output .= $prefix . "Explicit Tags: ";
	foreach( $object['etags'] as $etag ) {
		$etagcount--;
		$output .= $etag['tag'];
		if($etagcount) {
			$output .= " / ";
		} else {
			$output .= "\n";
		}
	}

	# Implicit Tags
	$itagcount = count($object['itags']);
	if($itagcount)
		$output .= $prefix . "Implicit Tags: ";
	foreach( $object['itags'] as $itag ) {
		$itagcount--;
		$output .= $itag['tag'];
		if($itagcount) {
			$output .= " / ";
		} else {
			$output .= "\n";
		}
	}

	# Comments
	$output .= $prefix . "Comment: " . $object['comment'] . "\n";

	# Ports and links
	$portcount = count($object['ports']);
	if($portcount) {
		$output .= $prefix . "Ports:\n";
		foreach( $object['ports'] as $port ) {
			$output .= $prefix . "  ";
			$output .= $port['name'] . " - ";
			$output .= $port['label'] . " - ";
			$output .= $port['iif_name'] . "/";
			$output .= $port['oif_name'];
			if( $port['linked'] ) {
				$output .= " <--> ";
				$output .= $port['remote_object_name'] . " / ";
				$output .= $port['remote_name'] . " - ";
				$output .= $port['cableid'] . " - ";
				$output .= $port['reservation_comment'];
			}
			$output .= "\n";
		}
	}

	# IPv4 addresses
	$ipv4count = count($object['ipv4']);
	if($ipv4count) {
		$output .= $prefix . "IPv4 Addresses:\n";
		foreach( $object['ipv4'] as $ipv4 ) {
			$output .= $prefix . "  ";
			$output .= $ipv4['osif'] . ": ";
			$output .= $ipv4['addrinfo']['ip'];
			$output .= "\n";
		}
	}

	# Log records
	$logrecords = getLogRecordsForObject($object['id']);
	if( count($logrecords) ) {
		$output .= $prefix . "Log Records:\n";
		foreach( $logrecords as $logentry ) {
			$output .= $prefix . "  " . $logentry['date'] . " - " . $logentry['user'] . " - " . $logentry['content'] . "\n";
		} 
	}

	return $output;
}

?>
