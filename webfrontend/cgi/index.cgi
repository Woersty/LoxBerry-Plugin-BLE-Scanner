#!/usr/bin/perl

# Copyright 2016 Christian Woerstenfeld, git@loxberry.woerstenfeld.de
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

use CGI::Carp qw(fatalsToBrowser);
use CGI qw/:standard/;
use Config::Simple;
use File::HomeDir;
use Data::Dumper;
use HTML::Entities;
use Cwd 'abs_path';
use warnings;
use strict;
no  strict "refs"; # we need it for template system

##########################################################################
# Variables
##########################################################################
our $cfg;
our $plugin_cfg;
our $ms_cfg;
our $phrase;
our $namef;
our $value;
our %query;
our $lang;
our $template_title;
our $help;
our @help;
our $helptext="";
our $helplink;
our $installfolder;
our $languagefile;
our $version;
our $error;
our $saveformdata=0;
our $output;
our $message;
our $nexturl;
our $do="form";
my  $home = File::HomeDir->my_home;
our $psubfolder;
our $bkpcounts;
our $languagefileplugin;
our $phraseplugin;
our %Config;
our @known_tags;
our @known_ms;
our @tag_cfg_data;
our $tag_comment;
our $tag_mac;
our $tag_use;
our $tag_use_hidden; 
our $tag_id;
our $ms_id;
our $tag_select;
our @config_params;
our $miniserver_to_use;
our $miniservers;
our @miniserver_id;
our @arr_miniservers;
our @arr_miniservernames;
our $ms_disabled;
our $miniserver_data;
our $ms_display_name;
our @language_strings;

##########################################################################
# Read Settings
##########################################################################

# Version of this script
$version = "0.16";


# Figure out in which subfolder we are installed
$psubfolder = abs_path($0);
$psubfolder =~ s/(.*)\/(.*)\/(.*)$/$2/g;

# Start with header
print "Content-Type: text/html\n\n"; 

# Read general config
$cfg            = new Config::Simple("$home/config/system/general.cfg");
$installfolder  = $cfg->param("BASE.INSTALLFOLDER");
$lang           = $cfg->param("BASE.LANG");
$miniservers    = $cfg->param("BASE.MINISERVERS");

# Get known Tags from plugin config
$plugin_cfg 		= new Config::Simple(syntax => 'ini');
$plugin_cfg 		= Config::Simple->import_from("$installfolder/config/plugins/$psubfolder/ble_scanner.cfg", \%Config)  or die Config::Simple->error();

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

# Everything from URL
foreach (split(/&/,$ENV{'QUERY_STRING'}))
{
  ($namef,$value) = split(/=/,$_,2);
  $namef =~ tr/+/ /;
  $namef =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
  $value =~ tr/+/ /;
  $value =~ s/%([a-fA-F0-9][a-fA-F0-9])/pack("C", hex($1))/eg;
  $query{$namef} = $value;
}

# Set parameters coming in - get over post
if ( !$query{'saveformdata'} ) { if ( param('saveformdata') ) { $saveformdata = quotemeta(param('saveformdata')); } else { $saveformdata = 0;      } } else { $saveformdata = quotemeta($query{'saveformdata'}); }
if ( !$query{'lang'} )         { if ( param('lang')         ) { $lang         = quotemeta(param('lang'));         } else { $lang         = $lang;  } } else { $lang         = quotemeta($query{'lang'});         }
if ( !$query{'do'} )           { if ( param('do')           ) { $do           = quotemeta(param('do'));           } else { $do           = "form"; } } else { $do           = quotemeta($query{'do'});           }

# Init Language
# Clean up lang variable
	$lang         =~ tr/a-z//cd; 
	$lang         = substr($lang,0,2);
	# If there's no language phrases file for choosed language, use german as default
	if (!-e "$installfolder/templates/system/$lang/language.dat") 
	{
		$lang = "de";
	}

# Read translations / phrases
	$languagefile 			= "$installfolder/templates/system/$lang/language.dat";
	$phrase 						= new Config::Simple($languagefile);
	$languagefileplugin = "$installfolder/templates/plugins/$psubfolder/$lang/language.dat";
	$phraseplugin 			= new Config::Simple($languagefileplugin);
	foreach my $key (keys %{ $phraseplugin->vars() } ) 
	{
		(my $cfg_section,my $cfg_varname) = split(/\./,$key,2);
		push @language_strings, $cfg_varname;
	}
	foreach our $template_string (@language_strings)
	{
		${$template_string} = $phraseplugin->param($template_string);
	}		

# Clean up saveformdata variable
	$saveformdata =~ tr/0-1//cd; 
	$saveformdata = substr($saveformdata,0,1);
	
##########################################################################
# Main program
##########################################################################

	if ($saveformdata) 
	{
	  &save;
	}
	else 
	{
	  &form;
	}
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
		$template_title = $phrase->param("TXT0000") . ": " . $phraseplugin->param("MY_NAME");

		# Print Template header
		&lbheader;

		# Read Miniservers from Config file
		our $cfg_ms     = new Config::Simple("$home/config/system/general.cfg");
		
		# Read IP/Hostname for each Miniserver into array @arr_miniservers
		for our $miniserver_id (1 .. $miniservers)
		{	
			push (@arr_miniservernames, $cfg_ms->param("MINISERVER$miniserver_id.NAME"));
			if ($cfg_ms->param("MINISERVER$miniserver_id.USECLOUDDNS") eq 1)
			{
				push (@arr_miniservers, $cfg_ms->param("MINISERVER$miniserver_id.CLOUDURL"));
			}
			else
			{			
				push (@arr_miniservers, $cfg_ms->param("MINISERVER$miniserver_id.IPADDRESS"));
			}

		}

		# Parse Tags into template
		for our $tag_id (0 .. $#known_tags)
		{
			# Parse variable tag_mag into template
			$tag_mac       = "BLE_00_00_00_00_00_00";
			if (defined($known_tags[$tag_id]->[0]))
			{
				$tag_mac     = $known_tags[$tag_id]->[0];
			}

			# Parse tag_use values into template
			$tag_use        = "unchecked";
			$tag_use_hidden = "off";
			if (defined($known_tags[$tag_id]->[1]))
			{
			 if ($known_tags[$tag_id]->[1] eq "on")
			 	{
					$tag_use        = "checked";
					$tag_use_hidden = "on";
				}
			}
						
			# Parse comment values into template
			$tag_comment = "-";
			if (defined($known_tags[$tag_id]->[3]))
			{
					$tag_comment = encode_entities($known_tags[$tag_id]->[3]);
			}

      # Parse miniserver Matrix
			our $miniserver_to_use=""; 
			for our $ms_id (0 .. $#arr_miniservers)
			{
				our $ms_number 				= $ms_id + 1;
				our $ms_used          = $tag_use; # Default value from Tag-Checkbox 
				our $ms_display_name  = $arr_miniservernames[$ms_id];
				our $ms_used_hidden   = $ms_used;
        if ($tag_use eq "unchecked") 
        {
        	$ms_disabled = "disabled";
        }
        else
        {
        	$ms_disabled = "";
        }
				if (defined($known_tags[$tag_id]->[2]))
				{
						our @tag_ms_use_list_data = split /\~/, $known_tags[$tag_id]->[2];
						foreach (sort keys @tag_ms_use_list_data) 
						{
							our @this_ms_use_data = split /\^/, $tag_ms_use_list_data[$_];
							if (defined($this_ms_use_data[0])) 
							{
								if ($ms_number eq $this_ms_use_data[0])
								{
									if (defined($this_ms_use_data[1]))
									{
										if ($this_ms_use_data[1] eq "on")
										{
												$ms_used 				= "checked";
												$ms_used_hidden = "on";
										}
										else
										{
												$ms_used 				= "unchecked";
												$ms_used_hidden = "off";
										}
									}
								}										
							}
							else
							{
								$ms_used 				= "unchecked";
								$ms_used_hidden = "off";
							}
						}
      	}

				open(F,"$installfolder/templates/plugins/$psubfolder/$lang/ms_column.html") || die "Missing template /plugins/$psubfolder/$lang/ms_column.html";
				while (<F>) 
				{
			     $_ =~ s/<!--\$(.*?)-->/${$1}/g;
					 $miniserver_to_use .= $_;
				}
		   	close(F);
				$ms_id ++;
	   	}
		 	
			open(F,"$installfolder/templates/plugins/$psubfolder/$lang/tag_row.html") || die "Missing template /plugins/$psubfolder/$lang/tag_row.html";
		  while (<F>) 
		  {
		     $_ =~ s/<!--\$(.*?)-->/${$1}/g;
				 $tag_select .= $_;
		  }
		  close(F);
		  $tag_id ++;
		}

    # Parse some strings from language file into template 
		our $str_tags    	  	= $phraseplugin->param("TXT_BLUETOOTH_TAGS");
		our $configured_tags  = $str_tags;
	
		# If there are no Tags change headline
		if ( $#known_tags eq -1)
		{
			$configured_tags  = $phraseplugin->param("TXT_NO_TAGS");
		}
		
		# Parse page
		open(F,"$installfolder/templates/plugins/$psubfolder/$lang/settings.html") || die "Missing template plugins/$psubfolder/$lang/settings.html";
		while (<F>) 
		{
			$_ =~ s/<!--\$(.*?)-->/${$1}/g;
		  print $_;
		}
		close(F);

		# Parse page footer		
		&footer;
		exit;
	}

#####################################################
# Save-Sub
#####################################################

	sub save 
	{
		# Write configuration file(s)

		@config_params = param; 
		our $save_config = 0;
		our $tag_id = 1;
		for my $tag_number (0 .. 256)
		{
			$plugin_cfg->delete("default.TAG$tag_number"); 
		}
		for our $config_id (0 .. $#config_params)
		{
			if ($config_params[$config_id] eq "saveformdata" && param($config_params[$config_id]) eq 1)
			{
				$save_config = 1;
			}
			else
			{
				if (substr($config_params[$config_id],0,4) eq "BLE_")
				{
					our $miniserver_data ='';
					for my $ms_number (1 .. param('miniservers'))
					{
						$miniserver_data .= $ms_number.'^'.param('MS_'.$config_params[$config_id].$ms_number).'~';
					}		 		  
					$miniserver_data = substr ($miniserver_data ,0, -1);
					$plugin_cfg->param("default.TAG$tag_id", $config_params[$config_id].':'.param($config_params[$config_id]).':'.$miniserver_data.':'.param(('comment'.$config_params[$config_id])));
					$tag_id ++;
		 		}
			}
			$config_id ++;
		}
		if ($save_config eq 1)
		{
			$plugin_cfg->save();
		}
		else
		{
		exit(1);
		}
		$template_title = $phrase->param("TXT0000") . ": " . $phraseplugin->param("MY_NAME");
		$message 				= $phraseplugin->param("TXT_SAVE_OK");
		$nexturl 				= "./index.cgi?do=form";
		
		# Print Template
		&lbheader;
		open(F,"$installfolder/templates/system/$lang/success.html") || die "Missing template system/$lang/succses.html";
		  while (<F>) 
		  {
		    $_ =~ s/<!--\$(.*?)-->/${$1}/g;
		    print $_;
		  }
		close(F);
		&footer;
		exit;
	}


#####################################################
# Error-Sub
#####################################################

	sub error 
	{
		$template_title = $phrase->param("TXT0000") . " - " . $phrase->param("TXT0028");
		
		&lbheader;
		open(F,"$installfolder/templates/system/$lang/error.html") || die "Missing template system/$lang/error.html";
    while (<F>) 
    {
      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
      print $_;
    }
		close(F);
		&footer;
		exit;
	}

#####################################################
# Page-Header-Sub
#####################################################

	sub lbheader 
	{
		 # Create Help page
	  $helplink = "http://www.loxwiki.eu/display/LOXBERRY/BLE-Scanner";
	  open(F,"$installfolder/templates/plugins/$psubfolder/$lang/help.html") || die "Missing template plugins/$psubfolder/$lang/help.html";
 		  while (<F>) 
		  {
		     $_ =~ s/<!--\$(.*?)-->/${$1}/g;
		     $helptext = $helptext . $_;
		  }

	  close(F);
	  open(F,"$installfolder/templates/system/$lang/header.html") || die "Missing template system/$lang/header.html";
	    while (<F>) 
	    {
	      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
	      print $_;
	    }
	  close(F);
	}

#####################################################
# Footer
#####################################################

	sub footer 
	{
	  open(F,"$installfolder/templates/system/$lang/footer.html") || die "Missing template system/$lang/footer.html";
	    while (<F>) 
	    {
	      $_ =~ s/<!--\$(.*?)-->/${$1}/g;
	      print $_;
	    }
	  close(F);
	}
