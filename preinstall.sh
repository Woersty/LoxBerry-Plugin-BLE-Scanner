#!/bin/sh

# Bash script which is executed by bash *BEFORE* installation is started (but
# *AFTER* preupdate). Use with caution and remember, that all systems may be
# different! Better to do this in your own Pluginscript if possible.
#
# Exit code must be 0 if executed successfull.
#
# Will be executed as user "loxberry".
#
# We add 4 arguments when executing the script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

#ARGV1=$1 # First argument is temp folder during install
#ARGV2=$2 # Second argument is Plugin-Name for scipts etc.
#ARGV3=$3 # Third argument is Plugin installation folder
#ARGV4=$4 # Forth argument is Plugin version

echo "<INFO> Check for sudoers entry for python"
/usr/bin/sudo grep "loxberry ALL = NOPASSWD: /usr/bin/python" /etc/sudoers
if [ $? -eq 1 ] 
then
  echo "<INFO> Adding sudoers entry for python"
  /usr/bin/sudo sh -c "echo \"\" >> /etc/sudoers" 
  /usr/bin/sudo sh -c "echo \"loxberry ALL = NOPASSWD: /usr/bin/python\" >> /etc/sudoers" 
	if [ $? -eq 1 ] 
	then
		# Exit with Status 1
		echo "<FAIL> Error during sudoers modification for python"
		exit 1
	else
		echo "<OK> Successful sudoers modification for python"
	fi
else
  echo "<INFO> sudoers entry for python exists"
fi

# Exit with Status 0
exit 0
