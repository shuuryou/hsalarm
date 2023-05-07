# Alarm Server for HiSilicon IP Cameras

There are a lot of cheap Chinese IP cameras available on Alibaba and AliExpress that use a chipset by a company called 海思 / 海思半导体有限公司 / 上海海思 / HiSilicon. These are often configured by a tool called _VMS_ and usually don't offer a working or useful web interface.

These cameras have support for what VMS calls an _Alarm Center_, whose configuration looks like this:

![Alarm center configuration in VMS](https://user-images.githubusercontent.com/36278767/236666939-094232bc-b7b1-460b-bcf3-4469d98b9414.png)

When configured correctly, this is the kind of data that is received:

```
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
```

This repository contains a small PHP script that can act as a server to receive alarm events from such IP cameras, e.g. when their built-in motion detection is triggered. All received events are stored in an SQLite database and can be processed further by other software from there. The script doesn't use any frameworks or other unnecessary garbage, but it does need PDO to talk to the database.

I wrote this in November 2022 and wanted to build something more sophisticated with it, but never found the time to do so. I'm putting the code here in the hope that it will help the one or two other people on the Internet that were looking for something like this.

To use the alarm server, create an SQLite database with this schema:

```sql
CREATE TABLE IF NOT EXISTS "events" (
        "ip"    INTEGER,
        "ts"    INTEGER,
        "status"  INTEGER,
        PRIMARY KEY("ip","ts","status")
) WITHOUT ROWID
;
CREATE INDEX "events_idx" ON "events" (
        "serialid",
        "ts"    ASC
);
```

Then edit the `eventdb` setting in the `hsalarm.ini` file and the `SETTINGS_FILE` definition in `hsalarm.php`.

If you use Systemd, you can use the files in the `systemd` folder as a starting point to get a socket listening on the default alarm center port (15002). If you don't use Systemd, you will need to set up something like DJB's [tcpserver](http://cr.yp.to/ucspi-tcp/tcpserver.html), or inetd. The script itself does not open a socket and just processes standard input. 

To adapt the the alarm server to your needs, it may help to set `DEBUG` to `TRUE` in `hsalarm.php` and to watch standard output to see what kind of stuff the IP camera sends (the Systemd example unit file will log debug output to the journal). As far as I know, the alarm center protocol is completely undocumented.

The way the alarm server works out of the box is that it will create a database entry with the IP address, current UNIX timestamp, and status (alarm triggerred = 1, alarm stopped = 0) every time an IP camera connects to it and sends a supported payload. I purposely avoided logging strings to the database in order to keep writes small and efficient, and overall processing time low.

## IP Addresses for Events

HiSilicon IP cameras send the IP address as part of the JSON object as a hexadecimal string. It's easy to figure out the IP address by just looking at the number. It's in the format `0xAABBCCDD`, where `AA`, `BB`, `CC` and `DD` are the four octets that make up the IP address. Convert them to decimal and enjoy the rest of your day.

This is surprisingly difficult to do in a platform independent manner in PHP, but because PHP has a built-in function for everything, you can chain together something ugly that works. Enjoy. :unamused:

###  Hexadecimal Number to IP Address

If you have the value of the IP address as a hexadecimal number from the debug output, e.g. `0x7B00000A`, run this to decode it:

```
# php -a
Interactive mode enabled

php > echo long2ip(unpack("V*", hex2bin("7B00000A"))[1]);
10.0.0.123
php > quit
```
### IP Address to Hexadecimal Number

To encode an IP address, e.g. `10.0.0.123` to the hexadecimal format used by the IP camera, run this:

```
# php -a
Interactive mode enabled

php > echo "0x".strtoupper(bin2hex(pack("V*", ip2long("10.0.0.123"))));
0x7B00000A
php > quit
```

### Integer to IP Address

If you have the value of the IP address as an integer from the `events.db` file, e.g. `2063597578`, run this:

```
# php -a
Interactive mode enabled

php > echo long2ip(unpack("N*", pack("L*", 2063597578))[1]);
10.0.0.123
php > quit
```

The reasoning for using integers for IP addresses in the `events.db` file is efficiency. Databases can deal with numbers a lot faster and easier than with strings. The events table basically contains a tuple of three numbers, which should make querying it extremely efficient.

### IP Address to Integer
To encode an IP address, e.g. `10.0.0.123` to an integer as recorded in the `events.db` file, run this.

```
# php -a
Interactive mode enabled

php > echo hexdec(bin2hex(pack("V*", ip2long("10.0.0.123"))));
2063597578
php > quit
```


