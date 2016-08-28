#!/bin/sh

# Bashscript which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems
# may be different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull.
#
# Will be executed as user "loxberry".
#
# We add 5 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>

ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

/bin/sed -i "s/REPLACEBYSUBFOLDER/$ARGV3/" $ARGV5/config/plugins/$ARGV3/ble_scanner.cfg
/bin/sed -i "s/REPLACEBYNAME/$ARGV2/" $ARGV5/config/plugins/$ARGV3/ble_scanner.cfg

/usr/bin/sudo /bin/hciconfig hci0 up
if [ $? -eq 1 ] 
then
	# Exit with Status 1
	echo "<FAIL> Error during start of bluetooth interface hci0 - please check"
	exit 1
else
	echo "<OK> Start of bluetooth interface hci0 [OK]"
fi

# Exit with Status 0
exit 0
