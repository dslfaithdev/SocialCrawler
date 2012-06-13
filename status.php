<pre>
<?
system('for A in posts_files/posts.txt.*
do FILE=${A##*\.}
  echo -n "$FILE: "
  TOTAL=`awk \'//{n++}; END {printf n/2}\' $A`
  ID=`tail -n 1 $A | awk -F "_" \'{print $1}\' `
  DONE=`grep $ID log/logFile.log | wc -l`
 # DONE=`find outputs/ -name ${FILE}_* |wc -l 2>/dev/null`
  echo "${TOTAL} ${DONE}" | \
    awk \'{ORS=" "; s=($2/$1)
      print $2 "/" $1 " => " s*100  "% "
      if (s >= 1) exit 0; exit 1}\' && \
   test \! -f ${FILE}.tgz && \
   find ./outputs/ -name "${FILE}_0*" | \
     tar -czf ${FILE}.tgz -T - && \
     find ./outputs/ -name "${FILE}_0*" -print0 | \
      xargs -0 -L 1000 rm -f
   if [ -f ${FILE}.tgz ]; then
     echo "<a href=\"${FILE}.tgz\">${FILE}.tgz</a>"
   else
    echo ""
  fi
done');
flush();
?>
</pre>
