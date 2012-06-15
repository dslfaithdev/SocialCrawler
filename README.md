Social Packets crawler
===============

S. Felix Wu
wu@cs.ucdavis.edu

Fredrik Erlandsson
fredrik.erlandsson@bth.se

This crawler consists of two stages, the _agent.php_ that does the actual crawling.
and a controller (found in _contoller/_).

Installation
--------------
Most of the time you only need to use the agent part..
Copy config/config-dist.php to config/config.php and insert.
**APP_ID**, **APP_SECRET** (from your facebook application page).
Also enter the correct **URL** to a running controller.

Usage
-------
run **php agent.php token=FACEBOOK_USER_TOKEN**
or. http://example.com/agent.php?token=FACEBOOK_USER_TOKEN

