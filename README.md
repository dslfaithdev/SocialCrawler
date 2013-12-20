Social Packets crawler
======================
S. Felix Wu, wu@cs.ucdavis.edu  
Fredrik Erlandsson, fredrik.erlandsson@bth.se


This crawler consists of two parts, the _agent.php_ that does the actual crawling 
and a controller (found in _contoller/_) keeping track of the current crawling status.

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

#### Crawling with test users ####

Extracts all the access tokens of your test users with **testuser.php** script:
```Shell
$ php testuser.php 
Write access token of test user #1 into TOKEN.1
Write access token of test user #2 into TOKEN.2
Write access token of test user #3 into TOKEN.3
Write access token of test user #4 into TOKEN.4
```

Check extracted tokens:
```Shell
$ ls TOKEN.*
TOKEN.1  TOKEN.2  TOKEN.3  TOKEN.4
```

launch the crawler with the token files:
```Shell
$ php agent.php token_file\=TOKEN.1
```

***Happy crawling!!***
