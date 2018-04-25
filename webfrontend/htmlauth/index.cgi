#!/usr/bin/perl

# Copyright 2016-2018 Christian Woerstenfeld, git@loxberry.woerstenfeld.de
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
# 
#     http://www.apache.org/licenses/LICENSE-2.0
# 
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


##########################################################################
# Modules
##########################################################################

use LoxBerry::System;
use LoxBerry::Web;
use LoxBerry::Log;
use CGI::Carp qw(fatalsToBrowser);
use CGI qw/:standard/;
use Config::Simple '-strict';
use HTML::Entities;
#use Cwd 'abs_path';
use warnings;
use strict;
no  strict "refs"; 

##########################################################################
# Variables
##########################################################################
my %Config;
my $logfile 					= "BLE-Scanner.log";
my $pluginconfigfile 			= "ble_scanner.cfg";
my $languagefile				= "language.ini";
my $maintemplatefilename 		= "settings.html";
my $helptemplatefilename		= "help.html";
my $errortemplatefilename 		= "error.html";
my $successtemplatefilename 	= "success.html";
my $error_message				= "";
my $no_error_template_message	= "<b>BLE-Scanner:</b> The error template is not readable. We must abort here. Please try to reinstall the plugin.";
my @pluginconfig_bletags;
my $template_title;
my $helpurl 					= "http://www.loxwiki.eu/display/LOXBERRY/BLE-Scanner";
my @tagrow;
my @tag;
my @known_tags;
my @tag_cfg_data;
my @msrow;
my @ms;
my $log 						= LoxBerry::Log->new ( name => 'BLE-Scanner', filename => $lbplogdir ."/". $logfile, append => 1 );
my $saveformdata=0;
my $do="form";
our $tag_id;
our $ms_id;
#our $tag_select;


#our $error;
#our $output;
#our $message;
#our $nexturl;
#my  $home = File::HomeDir->my_home;
#our $bkpcounts;
#our $languagefileplugin;
#our @known_ms;
our @config_params;
#our @miniserver_id;
#our $miniserver_data;
our @language_strings;

##########################################################################
# Read Settings
##########################################################################

# Version 
my $version = LoxBerry::System::pluginversion();
my $plugin = LoxBerry::System::plugindata();
my $cgi 	= CGI->new;
$cgi->import_names('R');

LOGSTART "New admin call."      if $plugin->{PLUGINDB_LOGLEVEL} eq 7;
$LoxBerry::System::DEBUG 	= 1 if $plugin->{PLUGINDB_LOGLEVEL} eq 7;
$LoxBerry::Web::DEBUG 		= 1 if $plugin->{PLUGINDB_LOGLEVEL} eq 7;
$log->loglevel($plugin->{PLUGINDB_LOGLEVEL});
LOGWARN "Cannot read loglevel from Plugin Database" if ( $plugin->{PLUGINDB_LOGLEVEL} eq "" );

LOGDEB "Init CGI and import names in namespace R::";
$R::delete_log if (0);
$R::do if (0);
$R::LOXBERRY_ID if (0);

if ( $R::delete_log )
{
	LOGWARN "Delete Logfile: ".$logfile;
	my $logfile = $log->close;
	system("/usr/bin/date > $logfile");
	$log->open;
	LOGSTART "Logfile restarted.";
	LOGOK "Version: ".$version;
	print "Content-Type: text/plain\n\nOK";
	exit;
}

stat($lbptemplatedir . "/" . $errortemplatefilename);
if ( !-r _ )
{
	$error_message = $no_error_template_message;
	LoxBerry::Web::lbheader($template_title, $helpurl, $helptemplatefilename);
	print $error_message;
	LOGCRIT $error_message;
	LoxBerry::Web::lbfooter();
	LOGCRIT "Leaving Plugin due to an unrecoverable error";
	exit;
}

my $errortemplate = HTML::Template->new(
		filename => $lbptemplatedir . "/" . $errortemplatefilename,
		global_vars => 1,
		loop_context_vars => 1,
		die_on_bad_params=> 0,
		associate => $cgi,
		%htmltemplate_options,
		debug => 1,
		);
my %ERR = LoxBerry::System::readlanguage($errortemplate, $languagefile);

stat($lbpconfigdir . "/" . $pluginconfigfile);
if (!-r _ ) 
{
	LOGWARN "Plugin config file not readable.";
	$error_message = $ERR{'ERRORS.ERR_CREATE_CONFIG_DIRECTORY'};
	mkdir $lbpconfigdir unless -d $lbpconfigdir or &error; 
	LOGDEB "Try to create a default config";
	$error_message = $ERR{'ERRORS.ERR_CREATE CONFIG_FILE'};
	open my $configfileHandle, ">", $lbpconfigdir . "/" . $pluginconfigfile or &error;
		print $configfileHandle 'LOXBERRY_ID=""'."\n";
	close $configfileHandle;
	LOGWARN "Default config created. Display error anyway to force a page reload";
	$error_message = $ERR{'ERRORS.ERR_NO_CONFIG_FILE'};
	&error; 
}


# Get known Tags from plugin config
my $plugin_cfg 		= new Config::Simple($lbpconfigdir . "/" . $pluginconfigfile);
$plugin_cfg 		= Config::Simple->import_from($lbpconfigdir . "/" . $pluginconfigfile,  \%Config)  or die Config::Simple->error();


my %miniservers;
%miniservers = LoxBerry::System::get_miniservers();
  
if (! %miniservers) 
{
	$error_message = $ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP'}."<br>".$ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP_SUGGESTION'};
	&error;
}
else
{
	$error_message = $ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP'}."<br>".$ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP_SUGGESTION'};
	&error if ( $miniservers{1}{IPAddress} eq "" );
	$error_message = "";
}
  
# Get through all the config options
foreach (sort keys %Config) 
{
	 # If option is a TAG process it
	 if ( substr($_, 0, 11) eq "default.TAG" ) 
	 { 
		  # Split config line into pieces - MAC, Comment and so on
	 	  @tag_cfg_data		 = split /:/, $Config{$_};
      
      # Remove spaces from MAC
      $tag_cfg_data[0] =~ s/^\s+|\s+$//g;
      
      # Put the current Tag info into the @known_tags array (MAC, Used, Miniservers, Comment and rest of the line)
	 		push (@known_tags, [ shift @tag_cfg_data, shift @tag_cfg_data, shift @tag_cfg_data, join(" ", @tag_cfg_data)]); 
	 }
}

$cgi->import_names('R');
$saveformdata = $R::saveformdata;
$do = $R::do;

LOGDEB "Get language";
my $lang	= lblanguage();
LOGDEB "Resulting language is: " . $lang;

my $maintemplate = HTML::Template->new(
		filename => $lbptemplatedir . "/" . $maintemplatefilename,
		global_vars => 1,
		loop_context_vars => 1,
		die_on_bad_params=> 0,
		%htmltemplate_options,
		debug => 1
		);
my %L = LoxBerry::System::readlanguage($maintemplate, $languagefile);
$maintemplate->param( "LBPPLUGINDIR", $lbpplugindir);
$maintemplate->param( "LOXBERRY_ID" , $Config{'default.LOXBERRY_ID'});
$maintemplate->param( "MINISERVERS"	, int( keys(%miniservers)) );
$maintemplate->param( "LOGO_ICON"	, get_plugin_icon(64) );
$maintemplate->param( "VERSION"		, $version);
$maintemplate->param( "LOGLEVEL" 	, $L{"LOGGING.LOGLEVEL".$plugin->{PLUGINDB_LOGLEVEL}});
$maintemplate->param( "LOGLEVEL" 	, "?" ) if ( $plugin->{PLUGINDB_LOGLEVEL} eq "" );
$lbplogdir =~ s/$lbhomedir\/log\///; # Workaround due to missing variable for Logview
$maintemplate->param( "LOGFILE" 	, $lbplogdir ."/". $logfile);
	
LOGDEB "Check, if filename for the successtemplate is readable";
stat($lbptemplatedir . "/" . $successtemplatefilename);
if ( !-r _ )
{
	LOGDEB "Filename for the successtemplate is not readable, that's bad";
	$error_message = $ERR{'ERRORS.ERR_SUCCESS_TEMPLATE_NOT_READABLE'};
	&error;
}
LOGDEB "Filename for the successtemplate is ok, preparing template";
my $successtemplate = HTML::Template->new(
		filename => $lbptemplatedir . "/" . $successtemplatefilename,
		global_vars => 1,
		loop_context_vars => 1,
		die_on_bad_params=> 0,
		associate => $cgi,
		%htmltemplate_options,
		debug => 1,
		);
LOGDEB "Read success strings from " . $languagefile . " for language " . $lang;
my %SUC = LoxBerry::System::readlanguage($successtemplate, $languagefile);

# Clean up saveformdata variable
	$saveformdata =~ tr/0-1//cd; 
	$saveformdata = substr($saveformdata,0,1);
	
##########################################################################
# Main program
##########################################################################


$R::saveformdata if 0; # Prevent errors
LOGDEB "Is it a save call?";
if ( $R::saveformdata ) 
{
	LOGDEB "Yes, is it a save call";
	@config_params = param; 
	our $save_config = 0;
	our $tag_id = 1;
	for my $tag_number (0 .. 256)
	{
		$plugin_cfg->delete("default.TAG$tag_number"); 
	}
	for our $config_id (0 .. $#config_params)
	{
		if (substr($config_params[$config_id],0,4) eq "BLE_")
		{
			LOGDEB "BLE_... found: ".$config_params[$config_id];
			our $miniserver_data ='';
			for my $msnumber (1 .. param('MINISERVERS'))
			{
				$miniserver_data .= $msnumber.'^'.param('MS_'.$config_params[$config_id].$msnumber).'~';
				LOGDEB "Processing MS $msnumber of ".param('MINISERVERS')." - ".$miniserver_data;
			}		 		  
			$miniserver_data = substr ($miniserver_data ,0, -1);
			$plugin_cfg->param("default.TAG$tag_id", $config_params[$config_id].':'.param($config_params[$config_id]).':'.$miniserver_data.':'.param(('comment'.$config_params[$config_id])));
			LOGDEB "Config line for default.TAG$tag_id: ".$config_params[$config_id].':'.param($config_params[$config_id]).':'.$miniserver_data.':'.param(('comment'.$config_params[$config_id]));
			$tag_id ++;
 		}
		$config_id ++;
	}
	$plugin_cfg->delete("default.saveformdata"); 
	$plugin_cfg->delete("default.SUBFOLDER"); 
	$plugin_cfg->delete("default.MINISERVERS"); 
	$plugin_cfg->delete("default.SCRIPTNAME"); 
	$plugin_cfg->param("default.LOXBERRY_ID", $R::LOXBERRY_ID); 
	LOGDEB "Write config to file";
	$error_message = $ERR{'ERRORS.ERR_SAVE_CONFIG_FILE'};
	$plugin_cfg->save() or &error; 
	LOGDEB "Set page title, load header, parse variables, set footer, end";
	$template_title = $SUC{'SAVE.MY_NAME'};
	LoxBerry::Web::lbheader($template_title, $helpurl, $helptemplatefilename);
	$successtemplate->param('SAVE_ALL_OK'		, $SUC{'SAVE.SAVE_ALL_OK'});
	$successtemplate->param('SAVE_MESSAGE'		, $SUC{'SAVE.SAVE_MESSAGE'});
	$successtemplate->param('SAVE_BUTTON_OK' 	, $SUC{'SAVE.SAVE_BUTTON_OK'});
	$successtemplate->param('SAVE_NEXTURL'		, $ENV{REQUEST_URI});
	print $successtemplate->output();
	LoxBerry::Web::lbfooter();
	LOGDEB "Leaving Plugin after saving the configuration.";
	exit;
}
else
{
	LOGDEB "No, not a save call";
}
LOGDEB "Call default page";
&form;
exit;

#####################################################
# 
# Subroutines
#
#####################################################

#####################################################
# Form-Sub
#####################################################

	sub form 
	{
		# The page title read from language file + our name
		$template_title = $L{"GENERAL.MY_NAME"};

		# Print Template header
		LoxBerry::Web::lbheader($template_title, $helpurl, $helptemplatefilename);

		# Parse Tags into template
		for our $tag_id (0 .. $#known_tags)
		{
			my %tag;
			# Parse variable tag_mag into template
			$tag{TAG_MAC} = "BLE_00_00_00_00_00_00";
			if (defined($known_tags[$tag_id]->[0]))
			{
				$tag{TAG_MAC}     = $known_tags[$tag_id]->[0];
			}

			# Parse tag_use values into template
			$tag{TAG_USE}    = "unchecked";
			$tag{TAG_USE_HIDDEN} = "off";
			if (defined($known_tags[$tag_id]->[1]))
			{
			 if ($known_tags[$tag_id]->[1] eq "on")
			 	{
					$tag{TAG_USE}        = "checked";
					$tag{TAG_USE_HIDDEN} = "on";
				}
			}
						
			# Parse comment values into template
			$tag{TAG_COMMENT} = "-";
			if (defined($known_tags[$tag_id]->[3]))
			{
					$tag{TAG_COMMENT} = encode_entities($known_tags[$tag_id]->[3]);
			}

      # Parse miniserver Matrix
	foreach my $ms_id (  sort keys  %miniservers) 
	{
		my %ms;
		$ms{MS_NUMBER}        = $ms_id;
		$ms{MS_USED}          = $tag{TAG_USE}; # Default value from Tag-Checkbox 
		$ms{MS_DISPLAY_NAME}  = $miniservers{$ms_id}{Name};
		LOGERR "MS".$ms{MS_NUMBER}." ".$ms{MS_DISPLAY_NAME}." ".$ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP'}."<br>".$ERR{'ERRORS.ERR_0007_MS_CONFIG_NO_IP_SUGGESTION'} if ( $miniservers{$ms_id}{IPAddress} eq "" );
		$ms{MS_USED_HIDDEN}   = $ms{MS_USED};
				if (defined($known_tags[$tag_id]->[2]))
				{
						our @tag_ms_use_list_data = split /\~/, $known_tags[$tag_id]->[2];
						foreach (sort keys @tag_ms_use_list_data) 
						{
							our @this_ms_use_data = split /\^/, $tag_ms_use_list_data[$_];
							if (defined($this_ms_use_data[0])) 
							{
								if ($ms{MS_NUMBER} eq $this_ms_use_data[0])
								{
									if (defined($this_ms_use_data[1]))
									{
										if ($this_ms_use_data[1] eq "on")
										{
												$ms{MS_USED} 				= "checked";
												$ms{MS_USED_HIDDEN}         = "on";
										}
										else
										{
												$ms{MS_USED} 				= "unchecked";
												$ms{MS_USED_HIDDEN}			= "off";
										}
									}
								}										
							}
							else
							{
								$ms{MS_USED} 				= "unchecked";
								$ms{MS_USED_HIDDEN}			= "off";
							}
						}
      	}
				$ms_id ++;
				push @{ $tag{'MSROW'} }, \%ms;
	   	}
			push(@tagrow, \%tag);
		  	$tag_id ++;
		}

    # Parse some strings from language file into template 
		our $str_tags    	  	= $L{"default.TXT_BLUETOOTH_TAGS"};
		our $configured_tags  = $str_tags;
	
		# If there are no Tags change headline
		if ( $#known_tags eq -1)
		{
			$configured_tags  = $L{"default.TXT_NO_TAGS"};
		}
		
		# Parse page

		# Parse page footer		
		$maintemplate->param("TAGROW" => \@tagrow);
		$maintemplate->param("LOXBERRY_ID" => $Config{'default.LOXBERRY_ID'});
    	print $maintemplate->output();
		LoxBerry::Web::lbfooter();
		exit;
	}

#####################################################
# Error-Sub
#####################################################
sub error 
{
	LOGDEB "Sub error";
	LOGERR $error_message;
	LOGDEB "Set page title, load header, parse variables, set footer, end with error";
	$template_title = $ERR{'ERRORS.MY_NAME'} . " - " . $ERR{'ERRORS.ERR_TITLE'};
	LoxBerry::Web::lbheader($template_title, $helpurl, $helptemplatefilename);
	$errortemplate->param('ERR_MESSAGE'		, $error_message);
	$errortemplate->param('ERR_TITLE'		, $ERR{'ERRORS.ERR_TITLE'});
	$errortemplate->param('ERR_BUTTON_BACK' , $ERR{'ERRORS.ERR_BUTTON_BACK'});
	print $errortemplate->output();
	LoxBerry::Web::lbfooter();
	LOGDEB "Leaving BLE-Scanner Plugin with an error";
	exit;
}

