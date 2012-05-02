#!/bin/sh

# Authenticate an facebook application directly from shell.
# Make sure to modify the EMAIL and PASS below and just run it as:
# sh facebook-login.sh "<your facebook url>"
#
# It's possible to script and run the script in a while loop (In my case this is necessary hence
# the facebook-sdk api for php causes random crashes and my script needs to run for a loooong time.
#
#  for a in a b c d e f g i j k l m n o p; do while (true); do START=`date +%s`; sh facebook-login.sh "<your app url>"; expr `date +%s` \< \( $START + 20 \) > /dev/null && break; done; sleep 30; done
#
# And, login notifications in facebook must be turned off..
#

EMAIL='YOUR_EMAIL' # edit this
PASS='YOUR_PASSWORD' # edit this

COOKIES='cookies${1}.txt'
USER_AGENT='Firefox'

URL=$2

REDIRECT_URL=`curl -X GET "${URL}" \
  --cookie $COOKIES --cookie-jar $COOKIES \
  -m 1 -s \
  | sed -e '/https/!d' -e 's/.*"\(http.*\)".*/\1/'`

if [ $REDIRECT_URL ]; then
  echo ${REDIRECT_URL}

  echo "Making oauth authentication.."
  curl -X GET "${REDIRECT_URL}" \
    --user-agent $USER_AGENT \
    --cookie $COOKIES --cookie-jar $COOKIES \
    --location \
    #-o /dev/null

  echo "Logging in to facebook.."
  curl -X POST 'https://login.facebook.com/login.php' \
    --user-agent $USER_AGENT \
    --data-urlencode "email=${EMAIL}" --data-urlencode "pass=${PASS}" \
    --cookie $COOKIES --cookie-jar $COOKIES \
    
    #-o /dev/null

URL=${REDIRECT_URL}
fi

echo "Fetching data."
curl -X GET "${URL}" \
  --user-agent $USER_AGENT \
  --cookie $COOKIES --cookie-jar $COOKIES \
  --location -N
