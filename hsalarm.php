#!/usr/bin/env php
<?php
/**
 * hsalarm.php
 *
 * The most basic, no bullshit, no frameworks, no bloat HiSilicon
 * Alarm Server. Designed for IP cameras using that specific SOC.
 *
 * To be used in conjunction with systemd socket activation or
 * something like DJB's tcpserver, or inetd. When a supported IP
 * camera connects to port 15002, it should be talking to this
 * script.
 *
 * @package    hsalarm
 * @copyright  2022
 * @license    AGPL v3
 * @link       https://github.com/shuuryou/hsalarm
 */

define('DEBUG', FALSE);
define('SETTINGS_FILE', '/opt/hsalarm/hsalarm.ini');

openlog('hsalarm', LOG_ODELAY | LOG_PID, LOG_LOCAL0);

$settings = parse_ini_file(SETTINGS_FILE, TRUE, INI_SCANNER_RAW);

if ($settings === FALSE)
{
	syslog(LOG_ERR, sprintf('Settings file "%s" could not be read.', SETTINGS_FILE));
	exit(1);
}

if (!array_key_exists('global', $settings) || !array_key_exists('eventdb', $settings['global']))
{
	syslog(LOG_ERR, sprintf('Settings file "%s" does not contain global eventdb setting.', SETTINGS_FILE));
	exit(1);
}

$db_file = realpath($settings['global']['eventdb']);

if ($db_file === FALSE || empty($db_file) || !is_writable($db_file))
{
	syslog(LOG_ERR, sprintf('Database file "%s" cannot be written to.', $settings['global']['eventdb']));
	exit(1);
}

$dbh = new PDO('sqlite:'. $db_file, SQLITE3_OPEN_READWRITE);
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbh->exec('pragma synchronous = off;');
$dbh->exec('pragma journal_mode = wal;');

/*
HiSilicon IP Cameras H265 cameras send this type of data when an
alarm occurs:

00000000: ff01 0000 0000 0000 0000 0000 0000 e405  ................
00000010: c000 0000 7b20 2241 6464 7265 7373 2220  ....{ "Address"
00000020: 3a20 2230 7843 4342 3241 3843 3022 2c20  : "0xCCB2A8C0",
00000030: 2243 6861 6e6e 656c 2220 3a20 302c 2022  "Channel" : 0, "
00000040: 4465 7363 7269 7022 203a 2022 222c 2022  Descrip" : "", "
00000050: 4576 656e 7422 203a 2022 4875 6d61 6e44  Event" : "HumanD
00000060: 6574 6563 7422 2c20 2253 6572 6961 6c49  etect", "SerialI
00000070: 4422 203a 2022 7878 7878 7878 7878 7878  D" : "xxxxxxxxxx
00000080: 7878 7878 7878 222c 2022 5374 6172 7454  xxxxxx", "StartT
00000090: 696d 6522 203a 2022 3230 3232 2d30 392d  ime" : "2022-09-
000000a0: 3235 2030 363a 3237 3a33 3322 2c20 2253  25 06:27:33", "S
000000b0: 7461 7475 7322 203a 2022 5374 6f70 222c  tatus" : "Stop",
000000c0: 2022 5479 7065 2220 3a20 2241 6c61 726d   "Type" : "Alarm
000000d0: 2220 7d0a                                " }.

They also send other kind of data using only a few bytes for
generic events. The initial sanity checks all exit(0) to eat
them silently.

*/

$in = stream_get_contents(STDIN);

if (empty($in))
{
	if (DEBUG) syslog(LOG_DEBUG, 'No data received.');
	exit(0);
}

$start = strpos($in, '{');

if ($start === FALSE)
{
	if (DEBUG) syslog(LOG_DEBUG, sprintf('Data received without JSON payload: %s', debug_escape_payload($in)));
	exit(0);
}

$in = substr($in, $start);

if (empty($in))
{
	syslog(LOG_NOTICE, sprintf('Data received with garbage JSON payload: %s', debug_escape_payload($in)));
	exit(0);
}

$in = json_decode($in, TRUE, 2, JSON_OBJECT_AS_ARRAY);

if (empty($in))
{
	syslog(LOG_NOTICE, sprintf('Data received with JSON payload that could not be decoded: %s', debug_escape_payload($in)));
	exit(0);
}

if (DEBUG) syslog(LOG_DEBUG, sprintf('JSON payload received: %s', var_export($in, TRUE)));


if (!array_key_exists('Type', $in))
{
	syslog(LOG_DEBUG, 'Data received with JSON payload contained no "Type" key-value pair.');
	exit(0);
}

if (!array_key_exists('Status', $in))
{
	syslog(LOG_DEBUG, 'Data received with JSON payload contained no "Status" key-value pair.');
	exit(0);
}

if (!array_key_exists('StartTime', $in))
{
	syslog(LOG_DEBUG, 'Data received with JSON payload contained no "StartTime" key-value pair.');
	exit(0);
}

if (!array_key_exists('Address', $in))
{
	syslog(LOG_DEBUG, 'Data received with JSON payload contained no "Address" key-value pair.');
	exit(0);
}

$Type = strtoupper($in['Type']);
$Status = strtoupper($in['Status']);
$Time = $in['StartTime'];
$Address = hexdec($in['Address']);

if (strtoupper($Type) != 'ALARM')
{
	if (DEBUG) syslog(LOG_NOTICE, sprintf('Data received with JSON payload that contained bad Type "%s"', $in['Type']));
	exit(0);
}


if (!in_array($Status, array('START', 'STOP')))
{
	syslog(LOG_NOTICE, sprintf('Data received with JSON payload that contained unknown status "%s"', $in['Status']));
	exit(0);
}

$Status = ($Status == 'START' ? 1 : 0);

$Time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $Time);

if ($Time === FALSE)
{
	syslog(LOG_NOTICE, sprintf('Data received with JSON payload that contained garbage "StartTime" value "%s"', $in['StartTime']));
	exit(0);
}

$Time = $Time->getTimestamp();

// The primary key is {ip,ts,status}, so INSERT OR IGNORE silently drops duplicate events
$statement = $dbh->prepare('INSERT OR IGNORE INTO events (ip, ts, status) VALUES (:ip, :ts, :status);');
$statement->bindParam(':ip', $Address);
$statement->bindParam(':ts', $Time);
$statement->bindParam(':status', $Status);
$statement->execute();

if (DEBUG) syslog(LOG_DEBUG, sprintf('Wrote event into database (%d, %d, %d)', $Address, $Time, $Status));

exit(0);

function debug_escape_payload($input)
{
	return preg_replace_callback('/[\x80-\xff]/', function($match) { return '0x'.dechex(ord($match[0])); }, $input);
}
