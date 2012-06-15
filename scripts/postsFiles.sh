#!/bin/sh

URL="http://dsl.ucdavis.edu/~wu/posts_TBC/status.php"
SELF="http://garm.comlab.bth.se/crawler.new/"

cd /usr/local/www/data/crawler.new

for A in posts_files/posts.txt.*; do
  if [ -f "$A" ]; then
    FILE=${A##*\.}
    echo -n "$FILE: "
    TOTAL=`awk '{n++}; END {printf n/2}' $A`
    ID=`tail -n 1 $A | awk -F "_" '{print $1}' `
    # DONE=`grep $ID log/logFile.log | wc -l`
    # DONE=`find outputs/ -name ${FILE}_* |wc -l 2>/dev/null`
    STATUS=`grep $ID log/logFile.log | awk '{ time+=$3; total++;} END  { print total " " time }'`
    DONE=${STATUS% *}
  #Make sure we are done with all entries in each of the posts.txt files.
    echo "${TOTAL} ${DONE}" | \
      awk '{ORS=" "; s=($2/$1); print $2 "/" $1 " => " s*100  "% \n"; if (s >= 1) exit 0; exit 1}'
    if [ $? -eq 0 ]; then # All done!
      if [ ! -f ${FILE}.tgz ]; then #File does not exist, lets create it.
        find ./outputs/ -name "${FILE}_0*" | tar -czf ${FILE}.tgz -T - && \
          find ./outputs/ -name "${FILE}_0*" -print0 | xargs -0 -L 1000 rm -f
      fi
      if [ -f ${FILE}.tgz ]; then
      #If so tell cyrus about that.
        #(http://dsl.ucdavis.edu/~wu/posts_TBC/status.php?activity='push'&file='file'&time='time'&url='url')
        #Move the posts.txt. file.
        #echo "<a href=\"${FILE}.tgz\">${FILE}.tgz</a>"
        mv ${A} done/new/
        curl -s "${URL}?action=push&file=posts.txt.${FILE}&time=${STATUS#* }&url=${SELF}${FILE}.tgz"
      fi
    fi
  fi
done

#How many files do we have?
P=`pwd`

while [ `ls -1 posts_files/posts.txt.*|wc -l` -le 3 ]; do
  #Pull a new posts.txt. file.
  cd posts_files
  curl -JO# "${URL}?action=pull" 2>/dev/null || exit 1
  cd $P
done

