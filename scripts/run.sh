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


# Global vars.

EMAIL='' # edit this
PASS='' # edit this

USER_AGENT='Firefox'
PIDS=""

authenticate() {
  REDIRECT_URL=$1
  COOKIES=cookies$2.txt

  echo "$2 Making oauth authentication.."
  curl -X GET "${REDIRECT_URL}" \
    --user-agent $USER_AGENT \
    --cookie $COOKIES --cookie-jar $COOKIES \
    --location \
    -s -o /dev/null

  echo "$2 Logging in to facebook.."
  curl -X POST 'https://login.facebook.com/login.php' \
    --user-agent $USER_AGENT \
    --data-urlencode "email=${EMAIL}" --data-urlencode "pass=${PASS}" \
    --cookie $COOKIES --cookie-jar $COOKIES \
    --location \
    -s -o /dev/null

  echo "$2 Trying once more.."
  curl -X GET "$REDIRECT_URL" \
    --user-agent $USER_AGENT \
    --cookie $COOKIES --cookie-jar $COOKIES \
    --location -s -m 12000 \
    |tee  cache$2

  REDIRECT_URL=`sed -e '/top\.location/!d'  \
    -e 's/.*"\(.*\)".*/\1/' < cache$2`

}


#Get data.
get_data() 
{
  URL=${2}
  while true; do
    echo "$1: $URL"
    curl -X GET "${URL}" \
      --user-agent $USER_AGENT \
      --cookie cookies${1}.txt --cookie-jar cookies${1}.txt \
      --location -m 12000 -s \
      | tee cache${1}

    REDIRECT_URL=`sed -e '/top\.location/!d'  \
      -e 's/.*"\(.*\)".*/\1/' < cache${1}`

    if [ -z "$REDIRECT_URL" ]
    then
      cp cache${1} error${1}_`date +%s`
      break
    fi

    if [ "${REDIRECT_URL#*facebook.com}" != "$REDIRECT_URL" ]
    then
      authenticate "$REDIRECT_URL" $1
    else
      URL="$REDIRECT_URL"
    fi

    sleep 30

  done
}

if [ $# -eq 2 ]
then
  OPERATOR="?"
  if [ "${2#*\?}" != "$2" ]; then
    OPERATOR="&"
  fi
 for ID in `seq $1`
   do
    get_data "$ID" "$2${OPERATOR}offset=$ID&chunk=$1" &
    echo "My PID=$$"
    echo "Background function PID=$!"
    PIDS="$PIDS $!"
 done
else
 get_data "1" "$1" &
fi

trap 'kill $PIDS $(jobs -p)' SIGINT SIGTERM

wait

