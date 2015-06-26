Social Packets crawler
======================
S. Felix Wu, wu@cs.ucdavis.edu  
Fredrik Erlandsson, fredrik.erlandsson@bth.se


This crawler consists of two parts, the _agent.php_ that does the actual crawling 
and a controller (found in _contoller/_) keeping track of the current crawling status.
 
______________________________________________________________________________________
### Install ###
The agent is dependent on the Facebook PHP SDK. To install just do a submodule update:

`git submodule update --init`

______________________________________________________________________________________
### Configuration ###
Most of the time you only need to use the agent.

Create a Facebook application at: https://developers.facebook.com/apps, 
make sure to fill in __offline_access__ & __read_stream__ under 
_Permissions_->_Extended Permissions_.


Copy config/config-dist.php to config/config.php and fill 
**APPID**, **APPSEC** (from your Facebook application page) &
the **URL** to a running controller.

#### Usage ####

run **php agent.php token\=FACEBOOK_USER_TOKEN**  
or as a web application *http://example.com/agent.php?token=FACEBOOK_USER_TOKEN*

To run multiple instances (reccomended) of the _agent_ in one environment use 
the script **bgxgrp.sh** as:  
**bash bgxgrp.sh <#-instances> php agent.php token\=FACEBOOK_USER_TOKEN**  
where <#-instances> should be replaced with the number of threads to run
(something between 8-15 is reasonable to not hit Facebook's 600/600 limit).

The __FACEBOOK_USER_TOKEN__ is generated via the graph explorer page
https://developers.facebook.com/tools/explorer/  using an user that is said to 
be over 18 of age to support crawling of all types of pages.





***Happy crawling!!***
