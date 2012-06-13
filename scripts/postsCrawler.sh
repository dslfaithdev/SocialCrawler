#!/bin/sh

APP_ID='270394906390981'
APP_SEC='06bba4a9f96db5b5fca37d4201636180'
AUTH=''
NAME="$1"
THREADS=8
LOGFILE="logFile.txt"

# bgxupdate - update active processes in a group.
#   Works by transferring each process to new group
#   if it is still active.
# in:  bgxgrp - current group of processes.
# out: bgxgrp - new group of processes.
# out: bgxcount - number of processes in new group.

bgxupdate() {
    bgxoldgrp=${bgxgrp}
    bgxgrp=""
    ((bgxcount = 0))
    bgxjobs=" $(jobs -pr | tr '\n' ' ')"
    for bgxpid in ${bgxoldgrp} ; do
        echo "${bgxjobs}" | grep " ${bgxpid} " >/dev/null 2>&1
        if [[ $? -eq 0 ]] ; then
            bgxgrp="${bgxgrp} ${bgxpid}"
            ((bgxcount = bgxcount + 1))
        fi
    done
}

# bgxlimit - start a sub-process with a limit.

#   Loops, calling bgxupdate until there is a free
#   slot to run another sub-process. Then runs it
#   an updates the process group.
# in:  $1     - the limit on processes.
# in:  $2+    - the command to run for new process.
# in:  bgxgrp - the current group of processes.
# out: bgxgrp - new group of processes

bgxlimit() {
    bgxmax=$1 ; shift
    bgxupdate
    while [[ ${bgxcount} -ge ${bgxmax} ]] ; do
        sleep 0.5
        bgxupdate
    done
    if [[ "$1" != "-" ]] ; then
        $* &
        bgxgrp="${bgxgrp} $!"
    fi
}

auth () {
AUTH=`curl -F type=client_cred \
  -F client_id=$APP_ID \
  -F client_secret=$APP_SEC \
  https://graph.facebook.com/oauth/access_token -s`
}

request() {
  OUT=`curl $1 -s`
  echo $OUT >> $2
  echo $OUT >> tmp
  PAGES=`echo $OUT |grep  -o 'paging":{"next":"[^"]*' | sed -e 's/.*"\(.*\)/\1/' -e 's/\u00252F/\//g' -e 's/\\\//g'`
# grep  -o 'paging":{"next"[^}]*}'` # \
 #    sed -e 's/.*"next":"\([^"]*\)".*/\1/' -e 's/\u00252F/\//g' -e 's/\\\//g'`
  echo $PAGES >> tmp
  for P in $PAGES
  do
    request $P $2
  done
}

make_request() {
  if [ "$auth" == "" ]; then
    auth
  fi

  printf "%s\t%s\t%s\n" `date +%s` "$ID" $(time \
    ( 
      OUT=`curl \
        -F "$AUTH" \
        -F 'batch=[
          {"method": "GET", "relative_url": "'$1'?limit=100"},
          {"method": "GET", "relative_url": "'$1'/likes?limit=100"},
          {"method": "GET", "relative_url": "'$1'/comments?limit=100"}
          ]' \
        https://graph.facebook.com -s`
      echo $OUT >> $2
      PAGES=`echo $OUT | grep  -o 'paging\\\":{\\\"next\\\"[^}]*}' \
       | sed -e 's/.*:\\\"\(.*\)\\\"}/\1/' -e 's/\u00252F/\//g' -e 's/\\\//g'`
      for P in $PAGES
      do
        request $P $2
      done
    ) 2>&1) >> $LOGFILE
}

COUNT=0
group1=""
TAB=$(echo -en "\t")
echo 0 $(date +%H:%M:%S) '[' ${group1} ']'

TIMEFORMAT=%3R
{ 
  while read DATE
  do
    read ID
    (( COUNT += 1 ))
    #Test if we already have run this thread.
    if grep -q "$TAB$ID$TAB" $LOGFILE 2> /dev/null
    then
      echo "$COUNT $ID already done, skipping.." >&2
      continue
    fi

    OUTFILE=`printf "%s_%08d_%13s_%s.json" "$NAME" "$COUNT" "${DATE::13}" "$ID"`
    bgxgrp=${group1}
    rm -f $OUTFILE 2>&1 > /dev/null
    bgxlimit $THREADS \
        make_request $ID $OUTFILE 
    group1=${bgxgrp}
    echo ${COUNT} $(date +%H:%M:%S) '[' ${group1} ']'
  done
}

unset TIMEFORMAT

# Wait until all others are finished.

echo
bgxgrp=${group1} ; bgxupdate ; group1=${bgxgrp}
while [[ ${bgxcount} -ne 0 ]] ; do
    oldcount=${bgxcount}
    while [[ ${oldcount} -eq ${bgxcount} ]] ; do
        sleep 1
        bgxgrp=${group1} ; bgxupdate ; group1=${bgxgrp}
    done
    echo 9 $(date +%H:%M:%S) '[' ${group1} ']'
done

